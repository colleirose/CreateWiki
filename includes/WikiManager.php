<?php

use MediaWiki\Shell\Shell;

class WikiManager {
	private $dbname = null;
	private $dbw = null;
	private $exists = null;
	private $tables = [];

	public function __construct( string $dbname ) {
		global $wgCreateWikiDatabase;

		$dbw = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase );

		$check = $dbw->selectRow(
			'cw_wikis',
			'wiki_dbname',
			[
				'wiki_dbname' => $dbname
			],
			__METHOD__
		);

		$this->dbname = $dbname;
		$this->dbw = $dbw;
		$this->exists = $check;
	}

	public function create(
		string $siteName,
		string $language,
		bool $private,
		bool $exempt,
		string $category,
		string $requester,
		string $actor,
		string $reason
	) {
		global $IP, $wgCreateWikiGlobalWiki, $wgCreateWikiSQLfiles;

		if ( $this->exists ) {
			throw new MWException( "Wiki '{$wiki}' already exists" );
		}

		$wiki = $this->dbname;

		$checkErrors = $this->checkDatabaseName( $wiki );

		if ( $checkErrors ) {
			return $checkErrors;
		}

		$this->dbw->insert(
			'cw_wikis',
			[
				'wiki_dbname' => $wiki,
				'wiki_sitename' => $siteName,
				'wiki_language' => $language,
				'wiki_private' => (int)$private,
				'wiki_creation' => $this->dbw->timestamp(),
				'wiki_inactive_exempt' => (int)$exempt,
				'wiki_category' => $category
			]
		);

		$this->dbw->query( 'SET storage_engine=InnoDB;' );
		$this->dbw->query( 'CREATE DATABASE ' . $this->dbw->addIdentifierQuotes( $wiki ) . ';' );

		Shell::makeScriptCommand(
			"$IP/extensions/CreateWiki/maintenance/DBListGenerator.php",
			[
				'--wiki', $wgCreateWikiGlobalWiki
			]
		)->limits( [ 'memory' => 0, 'filesize' => 0 ] )->execute();

		// Let's maintain our current connection ID
		$currentDB = $this->dbw->getDomainID();

		$this->dbw->selectDomain( $wiki );

		foreach ( $wgCreateWikiSQLfiles as $sqlfile ) {
			$this->dbw->sourceFile( $sqlfile );
		}

		// Now let's go back to our previous connection to avoid errors
		$this->dbw->selectDomain( $currentDB );

		Hooks::run( 'CreateWikiCreation', [ $wiki, $private ] );

		Shell::makeScriptCommand(
			"$IP/extensions/CreateWiki/maintenance/populateMainPage.php",
			[
				'--wiki', $wiki
			]
		)->limits( [ 'memory' => 0, 'filesize' => 0 ] )->execute();

		if ( ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
			Shell::makeScriptCommand(
				"$IP/extensions/CentralAuth/maintenance/createLocalAccount.php",
				[
					$requester,
					'--wiki', $wiki
				]
			)->limits( [ 'memory' => 0, 'filesize' => 0 ] )->execute();
		}

		Shell::makeScriptCommand(
			"$IP/maintenance/createAndPromote.php",
			[
				$requester,
				'--bureaucrat',
				'--sysop',
				'--force', // Let's not handle passwords
				'--wiki', $wiki
			]
		)->limits( [ 'memory' => 0, 'filesize' => 0 ] )->execute();

		$this->notificationsTrigger( 'creation', $wiki, [ 'siteName' => $siteName ], $requester );

		$this->logEntry( 'farmer', 'createwiki', $actor, $reason, [ '4::wiki' => $wiki ] );
	}

	public function delete( bool $force = false ) {
		global $wgCreateWikiDeletionDays;

		$this->compileTables();

		$wiki = $this->dbname;

		$row = $this->dbw->selectRow(
			'cw_wikis',
			'*',
			[
				'wiki_dbname' => $wiki
			]
		);

		$deletionDate = $row->wiki_deleted_timestamp;
		$unixDeletion = (int)wfTimestamp( TS_UNIX, $deletionDate );
		$unixNow = (int)wfTimestamp( TS_UNIX, $this->dbw->timestamp() );

		$deletedWiki = (bool)$row->wiki_deleted;

		if ( !$deletedWiki || !$force ) {
			if ( ( $unixNow - $unixDeletion ) < ( (int)$wgCreateWikiDeletionDays * 86400 ) ) {
				throw new MWException( "Wiki {$wiki} can not be deleted yet." );
			}
		}

		foreach ( $this->tables as $table => $selector ) {
			$this->dbw->delete(
				$table,
				[
					$selector => $wiki
				]
			);
		}

		Hooks::run( 'CreateWikiDeletion', [ $this->dbw, $wiki ] );
	}

	public function rename( string $new ) {
		$this->compileTables();

		$old = $this->dbname;

		$error = $this->checkDatabaseName( $new );

		if ( $error ) {
			throw new MWException( "Can not rename {$old} to {$new} because: {$error}" );
		}

		foreach ( (array)$this->tables as $table => $selector ) {
			$this->dbw->update(
				$table,
				[
					$selector => $new
				],
				[
					$selector => $old
				]
			);
		}

		Hooks::run( 'CreateWikiRename', [ $this->dbw, $old, $new ] );
	}

	private function compileTables() {
		$tables = [];

		Hooks::run( 'CreateWikiTables', [ &$tables ] );

		$tables['cw_wikis'] = 'wiki_dbname';

		$this->tables = $tables;
	}

	public function checkDatabaseName( string $dbname ) {
		global $wgConf;

		$suffixed = false;
		foreach( $wgConf->suffixes as $suffix ) {
			if ( substr( $dbname, -strlen( $suffix ) ) === $suffix ) {
				$suffixed = true;
				break;
			}
		}

		$error = false;
		if ( $this->dbw->query( 'SHOW DATABASES LIKE ' . $this->dbw->addQuotes( $dbname ) . ';' )->numRows() !== 0 ) {
			$error = 'dbexists';
		} elseif ( !$suffixed ) {
			$error = 'notsuffixed';
		} elseif( !ctype_alnum( $dbname ) ) {
			$error = 'notalnum';
		} elseif ( strtolower( $dbname ) !== $dbname ) {
			$error = 'notlowercase';
		}

		return ( $error ) ? wfMessage( 'createwiki-error-' . $error )->escaped() : false;
	}

	private function logEntry( string $log, string $action, string $actor, string $reason, array $params, string $loggingWiki = NULL ) {
		global $wgCreateWikiGlobalWiki;

		$logDBConn = wfGetDB( DB_MASTER, [], $loggingWiki ?? $wgCreateWikiGlobalWiki );

		$logEntry = new ManualLogEntry( $log, $action );
		$logEntry->setPerformer( User::newFromName( $actor ) );
		$logEntry->setTarget( Title::newFromID( 1 ) );
		$logEntry->setComment( $reason );
		$logEntry->setParameters( $params );
		$logID = $logEntry->insert( $logDBConn );
		$logEntry->publish( $logID );
	}

	public function notificationsTrigger( string $type, string $wiki, array $specialData, $receivers ) {
		global $wgCreateWikiUseEchoNotifications, $wgCreateWikiEmailNotifications, $wgPasswordSender, $wgSitename, $wgCreateWikiNotificationEmail, $wgCreateWikiSubdomain;

		switch ( $type ) {
			case 'creation':
				$echoType = 'wiki-creation';
				$echoExtra = [
					'wiki-url' => 'https://' . substr( $wiki, 0, -4 ) . ".{$wgCreateWikiSubdomain}",
					'sitename' => $specialData['siteName'],
					'notifyAgent' => true
				];
				$notifyServerAdministrators = false;
				break;
			case 'deletion':
				$echoType = false;
				break;
			case 'rename':
				$echoType = 'wiki-rename';
				$echoExtra = [
					'wiki-url' => 'https://' . substr( $wiki, 0, -4 ) . ".{$wgCreateWikiSubdomain}",
					'sitename' => $specialData['siteName'],
					'notifyAgent' => true
				];
				$notifyServerAdministrators = false; // temp
				break;
			case 'request-declined':
				$echoType = 'request-declined';
				$echoExtra = [
					'request-url' => SpecialPage::getTitleFor( 'Special:RequestWikiQueue', $specialData['id'] )->getFullURL(),
					'reason' => $specialData['reason'],
					'notifyAgent' => true
				];
				break;
		}

		if ( $wgCreateWikiUseEchoNotifications && $echoType ) {
			foreach ( (array)$receivers as $receiver ) {
				EchoEvent::create(
					[
						'type' => $echoType,
						'extra' => $echoExtra,
						'agent' => User::newFromName( $receiver )
					]
				);
			}
		}

		if ( $wgCreateWikiEmailNotifications && $type == 'creation' ) {
			$notifyEmails = [];

			foreach ( (array)$receivers as $receiver ) {
				$notifyEmails[] = MailAddress::newFromUser( User::newFromName( $receiver ) );
			}

			if ( $notifyServerAdministrators ) {
				$notifyEmails[] = new MailAddress( $wgCreateWikiNotificationEmail, 'Server Administrators' );
			}

			$from = new MailAddress( $wgPasswordSender, 'CreateWiki on ' . $wgSitename );
			$subject = wfMessage( 'createwiki-email-subject', $siteName )->inContentLanguage()->text();
			$body = wfMessage( 'createwiki-email-body' )->inContentLanguage()->text();

			UserMailer::send( $notifyEmails, $from, $subject, $body );
		}
	}
}