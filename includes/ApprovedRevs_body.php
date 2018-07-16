<?php

/**
 * Main class for the Approved Revs extension.
 *
 * @file
 * @ingroup Extensions
 *
 * @author Yaron Koren
 */
class ApprovedRevs {

	// Static arrays to prevent querying the database more than necessary.
	static $mApprovedContentForPage = array();
	static $mApprovedRevIDForPage = array();
	static $mApproverForPage = array();
	static $mUserCanApprove = null;
	/**
	 * @var array|null Variable holding array of users/groups able to approve
	 *     titles based upon page props set by parser function #approvable_by.
	 *     This variable has keys of page IDs and values as arrays of
	 *     usernames and user groups. Holding multiple page IDs likely isn't
	 *     necessary, but reduces security issue of one page's approvers being
	 *     misused for another page. Example:
	 *     [
	 *         1 => [
	 *             users => [ 'user1', 'user2' ],
	 *             groups => [ 'user1', 'user2' ],
	 *         ],
	 *         234 => [
	 *             users => [ 'user2', 'user3' ],
	 *             groups => [ 'group2', 'group3' ],
	 *         ]
	 *     ]
	 */
	private static $approversFromPageProp = null;

	/**
	 * Gets the approved revision User for this page, or null if there isn't
	 * one.
	 */
	public static function getRevApprover( $title ) {
		$pageID = $title->getArticleID();
		if ( !isset( self::$mApproverForPage[$pageID] ) && self::pageIsApprovable( $title ) ) {
			$dbr = wfGetDB( DB_SLAVE );
			$approverID = $dbr->selectField( 'approved_revs', 'approver_id',
				array( 'page_id' => $pageID ) );
			$approver = $approverID ? User::newFromID( $approverID ) : null;
			self::$mApproverForPage[$pageID] = $approver;
		}
		return $approver;
	}

	/**
	 * Gets the approved revision ID for this page, or null if there isn't
	 * one.
	 */
	public static function getApprovedRevID( $title ) {
		if ( $title == null ) {
			return null;
		}

		$pageID = $title->getArticleID();
		if ( array_key_exists( $pageID, self::$mApprovedRevIDForPage ) ) {
			return self::$mApprovedRevIDForPage[$pageID];
		}

		if ( ! self::pageIsApprovable( $title ) ) {
			return null;
		}

		$dbr = wfGetDB( DB_SLAVE );
		$revID = $dbr->selectField( 'approved_revs', 'rev_id', array( 'page_id' => $pageID ) );
		self::$mApprovedRevIDForPage[$pageID] = $revID;
		return $revID;
	}

	/**
	 * Returns whether or not this page has a revision ID.
	 */
	public static function hasApprovedRevision( $title ) {
		$revision_id = self::getApprovedRevID( $title );
		return ( ! empty( $revision_id ) );
	}

	/**
	 * Returns the contents of the specified wiki page, at either the
	 * specified revision (if there is one) or the latest revision
	 * (otherwise).
	 */
	public static function getPageText( $title, $revisionID = null ) {
		$revision = Revision::newFromTitle( $title, $revisionID );
		return $revision->getContent()->getNativeData();
	}

	/**
	 * Returns the content of the approved revision of this page, or null
	 * if there isn't one.
	 */
	public static function getApprovedContent( $title ) {
		$pageID = $title->getArticleID();
		if ( array_key_exists( $pageID, self::$mApprovedContentForPage ) ) {
			return self::$mApprovedContentForPage[$pageID];
		}

		$revisionID = self::getApprovedRevID( $title );
		if ( empty( $revisionID ) ) {
			return null;
		}
		$text = self::getPageText( $title, $revisionID );
		self::$mApprovedContentForPage[$pageID] = $text;
		return $text;
	}

	/**
	 * Helper function - returns whether the user is currently requesting
	 * a page via the simple URL for it - not specfying a version number,
	 * not editing the page, etc.
	 */
	public static function isDefaultPageRequest( $request ) {
		if ( $request->getCheck( 'oldid' ) ) {
			return false;
		}
		// Check if it's an action other than viewing.
		if ( $request->getCheck( 'action' ) &&
			$request->getVal( 'action' ) != 'view' &&
			$request->getVal( 'action' ) != 'purge' &&
			$request->getVal( 'action' ) != 'render' ) {
				return false;
		}
		return true;
	}

	/**
	 * Returns whether this page can be approved - either because it's in
	 * a supported namespace, or because it's been specially marked as
	 * approvable. Also stores the boolean answer as a field in the page
	 * object, to speed up processing if it's called more than once.
	 */
	public static function pageIsApprovable( Title $title ) {
		// If this function was already called for this page, the value
		// should have been stored as a field in the $title object.
		if ( isset( $title->isApprovable ) ) {
			return $title->isApprovable;
		}

		if ( !$title->exists() ) {
			$title->isApprovable = false;
			return $title->isApprovable;
		}

		// Allow custom setting of whether the page is approvable.
		if ( !Hooks::run( 'ApprovedRevsPageIsApprovable', array( $title, &$isApprovable ) ) ) {
			$title->isApprovable = $isApprovable;
			return $title->isApprovable;
		}

		// Check the namespace.
		global $egApprovedRevsNamespaces;
		if ( in_array( $title->getNamespace(), $egApprovedRevsNamespaces ) ) {
			$title->isApprovable = true;
			return $title->isApprovable;
		}

		// It's not in an included namespace, so check for the page
		// properties for the parser functions - for some reason, calling the standard
		// getProperty() function doesn't work, so we just do a DB
		// query on the page_props table.
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( 'page_props', 'COUNT(*)',
			[
				'pp_page' => $title->getArticleID(),
				'pp_propname' => [ 'approvedrevs-approver-users', 'approvedrevs-approver-groups' ],
			]
		);
		$row = $dbr->fetchRow( $res );
		if ( intval( $row[0] ) > 0 ) {
			$title->isApprovable = true;
			return $isApprovable;
		}

		// parser function page properties not present. Check for magic word.
		$res = $dbr->select( 'page_props', 'COUNT(*)',
			array(
				'pp_page' => $title->getArticleID(),
				'pp_propname' => 'approvedrevs',
				'pp_value' => 'y'
			)
		);
		$row = $dbr->fetchRow( $res );
		$isApprovable = ( $row[0] == '1' );
		$title->isApprovable = $isApprovable;
		return $isApprovable;
	}

	public static function checkPermission( User $user, Title $title, $permission ) {
		return ( $title->userCan( $permission, $user ) || $user->isAllowed( $permission ) );
	}

	public static function userCanApprove( User $user, Title $title ) {
		global $egApprovedRevsSelfOwnedNamespaces;
		$permission = 'approverevisions';

		// $mUserCanApprove is a static variable used for
		// "caching" the result of this function, so that
		// it only has to be called once.
		if ( self::$mUserCanApprove ) {
			return true;
		} elseif ( self::$mUserCanApprove === false ) {
			return false;
		} elseif ( ApprovedRevs::checkPermission( $user, $title, $permission ) ) {
			self::$mUserCanApprove = true;
			return true;
		} elseif ( ApprovedRevs::checkParserFunctionPermission( $user, $title ) ) {
			self::$mUserCanApprove = true;
			return true;
		} else {
			// If the user doesn't have the 'approverevisions'
			// permission, nor does #approvable_by grant them
			// permission, they still might be able to approve
			// revisions - it depends on whether the current
			// namespace is within the admin-defined
			// $egApprovedRevsSelfOwnedNamespaces array.
			$namespace = $title->getNamespace();
			if ( in_array( $namespace, $egApprovedRevsSelfOwnedNamespaces ) ) {
				if ( $namespace == NS_USER ) {
					// If the page is in the 'User:'
					// namespace, this user can approve
					// revisions if it's their user page.
					if ( $title->getText() == $user->getName() ) {
						self::$mUserCanApprove = true;
						return true;
					}
				} else {
					// Otherwise, they can approve revisions
					// if they created the page.
					// We get that information via a SQL
					// query - is there an easier way?
					$dbr = wfGetDB( DB_SLAVE );
					$row = $dbr->selectRow(
						array( 'r' => 'revision', 'p' => 'page' ),
						'r.rev_user_text',
						array( 'p.page_title' => $title->getDBkey() ),
						null,
						array( 'ORDER BY' => 'r.rev_id ASC' ),
						array( 'revision' => array( 'JOIN', 'r.rev_page = p.page_id' ) )
					);
					if ( $row->rev_user_text == $user->getName() ) {
						self::$mUserCanApprove = true;
						return true;
					}
				}
			}
		}
		self::$mUserCanApprove = false;
		return false;
	}

	/**
	 * Check if a user is allowed to approve a page based upon being listed in
	 * the page properties approvedrevs-approver-users and
	 * approvedrevs-approver-groups
	 *
	 * @param User $user Check if this user has #approvable_by permissions on title
	 * @param Title $title Title to check
	 * @return bool Whether or not approving revisions is allowed
	 * @since 0.9
	 */
	public static function checkParserFunctionPermission( User $user, Title $title ) {

		// init self::$approversFromPageProp if needed
		if ( self::$approversFromPageProp === null ) {
			self::$approversFromPageProp = [];
		}
		$articleID = $title->getArticleID();
		// if page props not set for this title, set them
		if ( !isset( self::$approversFromPageProp[$articleID] ) ) {
			self::$approversFromPageProp[$articleID] = [];

			$dbr = wfGetDB( DB_SLAVE );
			$approvers = [];
			foreach ( ['users','groups'] as $type ) {
				$approvers = $dbr->selectField(
					'page_props',
					'pp_value',
					[
						'pp_page' => $title->getArticleID(),
						'pp_propname' => "approvedrevs-approver-$type"
					],
					__METHOD__
				);

				if ( $approvers === false ) {
					self::$approversFromPageProp[$articleID][$type] = [];
				}
				else {
					self::$approversFromPageProp[$articleID][$type] = explode( ',', $approvers );
				}
			}
		}

		// if user listed as an approver
		if ( in_array( $user->getName(), self::$approversFromPageProp[$articleID]['users'] ) ) {
			return true;
		}

		// intersect groups that can approve with user's group
		$userGroupsWithApprove = array_intersect(
			self::$approversFromPageProp[$articleID]['groups'], $user->getGroups()
		);

		// if user has any groups in list of approver groups, allow approval
		if ( count( $userGroupsWithApprove ) > 0 ) {
			return true;
		}

		// neither group nor username allowed approval...disallow
		return false;
	}

	public static function saveApprovedRevIDInDB( $title, $rev_id, $isAutoApprove = true ) {
		global $wgUser;
		$userBit = array();

		if ( !$isAutoApprove ) {
			$userBit = array( 'approver_id' => $wgUser->getID() );
		}

		$dbr = wfGetDB( DB_MASTER );
		$page_id = $title->getArticleID();
		$old_rev_id = $dbr->selectField( 'approved_revs', 'rev_id', array( 'page_id' => $page_id ) );
		if ( $old_rev_id ) {
			$dbr->update( 'approved_revs',
				array_merge( array( 'rev_id' => $rev_id ), $userBit ),
				array( 'page_id' => $page_id ) );
		} else {
			$dbr->insert( 'approved_revs',
				array_merge( array( 'page_id' => $page_id, 'rev_id' => $rev_id ), $userBit ) );
		}
		// Update "cache" in memory
		self::$mApprovedRevIDForPage[$page_id] = $rev_id;
		self::$mApproverForPage[$page_id] = $wgUser;
	}

	static function setPageSearchText( $title, $text ) {
		DeferredUpdates::addUpdate( new SearchUpdate( $title->getArticleID(), $title->getText(), $text ) );
	}

	/**
	 * Sets a certain revision as the approved one for this page in the
	 * approved_revs DB table; calls a "links update" on this revision
	 * so that category information can be stored correctly, as well as
	 * info for extensions such as Semantic MediaWiki; and logs the action.
	 */
	public static function setApprovedRevID( $title, $rev_id, $is_latest = false ) {
		self::saveApprovedRevIDInDB( $title, $rev_id, false );
		$parser = new Parser();

		// If the revision being approved is definitely the latest
		// one, there's no need to call the parser on it.
		if ( !$is_latest ) {
			$parser->setTitle( $title );
			$text = self::getPageText( $title, $rev_id );
			$options = new ParserOptions();
			$parser->parse( $text, $title, $options, true, true, $rev_id );
			$u = new LinksUpdate( $title, $parser->getOutput() );
			$u->doUpdate();
			self::setPageSearchText( $title, $text );
		}

		$log = new LogPage( 'approval' );
		$rev_url = $title->getFullURL( array( 'oldid' => $rev_id ) );
		$rev_link = Xml::element(
			'a',
			array( 'href' => $rev_url ),
			$rev_id
		);
		$logParams = array( $rev_link );
		$log->addEntry(
			'approve',
			$title,
			'',
			$logParams
		);

		Hooks::run( 'ApprovedRevsRevisionApproved', array( $parser, $title, $rev_id ) );
	}

	public static function deleteRevisionApproval( $title ) {
		$dbr = wfGetDB( DB_MASTER );
		$page_id = $title->getArticleID();
		$dbr->delete( 'approved_revs', array( 'page_id' => $page_id ) );
	}

	/**
	 * Unsets the approved revision for this page in the approved_revs DB
	 * table; calls a "links update" on this page so that category
	 * information can be stored correctly, as well as info for
	 * extensions such as Semantic MediaWiki; and logs the action.
	 */
	public static function unsetApproval( $title ) {
		global $egApprovedRevsBlankIfUnapproved;

		self::deleteRevisionApproval( $title );

		$parser = new Parser();
		$parser->setTitle( $title );
		if ( $egApprovedRevsBlankIfUnapproved ) {
			$text = '';
		} else {
			$text = self::getPageText( $title );
		}
		$options = new ParserOptions();
		$parser->parse( $text, $title, $options );
		$u = new LinksUpdate( $title, $parser->getOutput() );
		$u->doUpdate();
		self::setPageSearchText( $title, $text );

		$log = new LogPage( 'approval' );
		$log->addEntry(
			'unapprove',
			$title,
			''
		);

		Hooks::run( 'ApprovedRevsRevisionUnapproved', array( $parser, $title ) );
	}

	public static function addCSS() {
		global $wgOut;
		$wgOut->addModuleStyles( 'ext.ApprovedRevs' );
	}

	/**
	 * Helper function for backward compatibility.
	 */
	public static function makeLink( $linkRenderer, $title, $msg = null, $attrs = array(), $params = array() ) {
		if ( !is_null( $linkRenderer ) ) {
			// MW 1.28+
			return $linkRenderer->makeLink( $title, $msg, $attrs, $params );
		} else {
			return Linker::linkKnown( $title, $msg, $attrs, $params );
		}
	}

}
