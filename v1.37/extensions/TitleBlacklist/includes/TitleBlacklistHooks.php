<?php
/**
 * Hooks for Title Blacklist
 * @author Victor Vasiliev
 * @copyright © 2007-2010 Victor Vasiliev et al
 * @license GPL-2.0-or-later
 */

use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserIdentity;

/**
 * Hooks for the TitleBlacklist class
 *
 * @ingroup Extensions
 */
class TitleBlacklistHooks implements
	\MediaWiki\Hook\EditFilterHook,
	\MediaWiki\Hook\TitleGetEditNoticesHook,
	\MediaWiki\Hook\MovePageCheckPermissionsHook,
	\MediaWiki\Permissions\Hook\GetUserPermissionsErrorsExpensiveHook,
	\MediaWiki\Storage\Hook\PageSaveCompleteHook
{

	/**
	 * getUserPermissionsErrorsExpensive hook
	 *
	 * @param Title $title
	 * @param User $user
	 * @param string $action
	 * @param array|string|MessageSpecifier &$result
	 *
	 * @return bool
	 */
	public function onGetUserPermissionsErrorsExpensive( $title, $user, $action, &$result ) {
		# Some places check createpage, while others check create.
		# As it stands, upload does createpage, but normalize both
		# to the same action, to stop future similar bugs.
		if ( $action === 'createpage' || $action === 'createtalk' ) {
			$action = 'create';
		}
		if ( $action == 'create' || $action == 'edit' || $action == 'upload' ) {
			$blacklisted = TitleBlacklist::singleton()->userCannot( $title, $user, $action );
			if ( $blacklisted instanceof TitleBlacklistEntry ) {
				$errmsg = $blacklisted->getErrorMessage( 'edit' );
				$params = [
					$blacklisted->getRaw(),
					$title->getFullText()
				];
				ApiResult::setIndexedTagName( $params, 'param' );
				$result = ApiMessage::create(
					wfMessage(
						$errmsg,
						htmlspecialchars( $blacklisted->getRaw() ),
						$title->getFullText()
					),
					'titleblacklist-forbidden',
					[
						'message' => [
							'key' => $errmsg,
							'params' => $params,
						],
						'line' => $blacklisted->getRaw(),
						// As $errmsg usually represents a non-default message here, and ApiBase
						// uses ->inLanguage( 'en' )->useDatabase( false ) for all messages, it will
						// never result in useful 'info' text in the API. Try this, extra data seems
						// to override the default.
						'info' => 'TitleBlacklist prevents this title from being created',
					]
				);
				return false;
			}
		}
		return true;
	}

	/**
	 * Display a notice if a user is only able to create or edit a page
	 * because they have tboverride.
	 *
	 * @param Title $title
	 * @param int $oldid
	 * @param array &$notices
	 */
	public function onTitleGetEditNotices( $title, $oldid, &$notices ) {
		if ( !RequestContext::getMain()->getUser()->isAllowed( 'tboverride' ) ) {
			return;
		}

		$blacklisted = TitleBlacklist::singleton()->isBlacklisted(
			$title,
			$title->exists() ? 'edit' : 'create'
		);
		if ( !$blacklisted ) {
			return;
		}

		$params = $blacklisted->getParams();
		if ( isset( $params['autoconfirmed'] ) ) {
			return;
		}

		$msg = wfMessage( 'titleblacklist-warning' );
		$notices['titleblacklist'] = $msg->plaintextParams( $blacklisted->getRaw() )
			->parseAsBlock();
	}

	/**
	 * MovePageCheckPermissions hook (1.25+)
	 *
	 * @param Title $oldTitle
	 * @param Title $newTitle
	 * @param User $user
	 * @param string $reason
	 * @param Status $status
	 *
	 * @return bool
	 */
	public function onMovePageCheckPermissions(
		$oldTitle, $newTitle, $user, $reason, $status
	) {
		$titleBlacklist = TitleBlacklist::singleton();
		$blacklisted = $titleBlacklist->userCannot( $newTitle, $user, 'move' );
		if ( !$blacklisted ) {
			$blacklisted = $titleBlacklist->userCannot( $oldTitle, $user, 'edit' );
		}
		if ( $blacklisted instanceof TitleBlacklistEntry ) {
			$status->fatal( ApiMessage::create( [
				$blacklisted->getErrorMessage( 'move' ),
				$blacklisted->getRaw(),
				$oldTitle->getFullText(),
				$newTitle->getFullText()
			] ) );
			return false;
		}

		return true;
	}

	/**
	 * Check whether a user name is acceptable for account creation or autocreation, and explain
	 * why not if that's the case.
	 *
	 * @param string $userName
	 * @param User $creatingUser
	 * @param bool $override Should the test be skipped, if the user has sufficient privileges?
	 * @param bool $log Log blacklist hits to Special:Log
	 *
	 * @return StatusValue
	 */
	public static function testUserName(
		$userName, User $creatingUser, $override = true, $log = false
	) {
		$title = Title::makeTitleSafe( NS_USER, $userName );
		$blacklisted = TitleBlacklist::singleton()->userCannot( $title, $creatingUser,
			'new-account', $override );
		if ( $blacklisted instanceof TitleBlacklistEntry ) {
			if ( $log ) {
				self::logFilterHitUsername( $creatingUser, $title, $blacklisted->getRaw() );
			}
			$message = $blacklisted->getErrorMessage( 'new-account' );
			$params = [
				$blacklisted->getRaw(),
				$userName,
			];
			ApiResult::setIndexedTagName( $params, 'param' );
			return StatusValue::newFatal( ApiMessage::create(
				[ $message, $blacklisted->getRaw(), $userName ],
				'titleblacklist-forbidden',
				[
					'message' => [
						'key' => $message,
						'params' => $params,
					],
					'line' => $blacklisted->getRaw(),
					// The text of the message probably isn't useful API info, so do this instead
					'info' => 'TitleBlacklist prevents this username from being created',
				]
			) );
		}
		return StatusValue::newGood();
	}

	/**
	 * EditFilter hook
	 *
	 * @param EditPage $editor
	 * @param string $text
	 * @param string $section
	 * @param string &$error
	 * @param string $summary
	 */
	public function onEditFilter( $editor, $text, $section, &$error, $summary ) {
		$title = $editor->getTitle();

		if ( $title->getNamespace() == NS_MEDIAWIKI && $title->getDBkey() == 'Titleblacklist' ) {
			$blackList = TitleBlacklist::singleton();
			$bl = TitleBlacklist::parseBlacklist( $text, 'page' );
			$ok = $blackList->validate( $bl );
			if ( $ok === [] ) {
				return;
			}

			$errmsg = wfMessage( 'titleblacklist-invalid' )->numParams( count( $ok ) )->text();
			$errlines = '* <code>' .
				implode( "</code>\n* <code>", array_map( 'wfEscapeWikiText', $ok ) ) .
				'</code>';
			$error = Html::openElement( 'div', [ 'class' => 'errorbox' ] ) .
				$errmsg .
				"\n" .
				$errlines .
				Html::closeElement( 'div' ) . "\n" .
				Html::element( 'br', [ 'clear' => 'all' ] ) . "\n";

			// $error will be displayed by the edit class
		}
	}

	/**
	 * PageSaveComplete hook
	 *
	 * @param WikiPage $wikiPage
	 * @param UserIdentity $userIdentity
	 * @param string $summary
	 * @param int $flags
	 * @param RevisionRecord $revisionRecord
	 * @param EditResult $editResult
	 */
	public function onPageSaveComplete(
		$wikiPage,
		$userIdentity,
		$summary,
		$flags,
		$revisionRecord,
		$editResult
	) {
		$title = $wikiPage->getTitle();
		if ( $title->getNamespace() === NS_MEDIAWIKI && $title->getDBkey() == 'Titleblacklist' ) {
			TitleBlacklist::singleton()->invalidate();
		}
	}

	/**
	 * Logs the filter username hit to Special:Log if
	 * $wgTitleBlacklistLogHits is enabled.
	 *
	 * @param User $user
	 * @param Title $title
	 * @param string $entry
	 */
	public static function logFilterHitUsername( $user, $title, $entry ) {
		global $wgTitleBlacklistLogHits;
		if ( $wgTitleBlacklistLogHits ) {
			$logEntry = new ManualLogEntry( 'titleblacklist', 'hit-username' );
			$logEntry->setPerformer( $user );
			$logEntry->setTarget( $title );
			$logEntry->setParameters( [
				'4::entry' => $entry,
			] );
			$logid = $logEntry->insert();
			$logEntry->publish( $logid );
		}
	}

	/**
	 * External Lua library for Scribunto
	 *
	 * @param string $engine
	 * @param array &$extraLibraries
	 */
	public static function onScribuntoExternalLibraries( $engine, array &$extraLibraries ) {
		if ( $engine == 'lua' ) {
			$extraLibraries['mw.ext.TitleBlacklist'] = 'Scribunto_LuaTitleBlacklistLibrary';
		}
	}
}
