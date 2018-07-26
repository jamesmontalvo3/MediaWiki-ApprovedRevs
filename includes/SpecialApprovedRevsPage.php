<?php

/**
 * Special page that displays various lists of pages that either do or do
 * not have an approved revision.
 *
 * @author Yaron Koren
 */
class SpecialApprovedRevsPage extends QueryPage {

	protected $mMode;

	/**
	 * @var Array $mHeaderLinks: pairs mode with messages. E.g. mode "allfiles"
	 *      used to generate a header link with query string having "show=allfiles"
	 *      and link text of "All files with an approved revision" (in English).
	 */
	protected $mHeaderLinks = [

		// for approved page revs
		'approvedrevs-notlatestpages'     => '',
		'approvedrevs-unapprovedpages'    => 'unapproved',
		'approvedrevs-approvedpages'      => 'all',
		'approvedrevs-invalidpages'       => 'invalid',

		// for approved file revs
		'approvedrevs-notlatestpages'     => 'notlatestfiles',
		'approvedrevs-unapprovedpages'    => 'unapprovedfiles',
		'approvedrevs-approvedpages'      => 'allfiles',
		'approvedrevs-invalidpages'       => 'invalidfiles',
	];

	public function __construct( $mode ) {
		if ( $this instanceof SpecialPage ) {
			parent::__construct( 'ApprovedRevs' );
		}
		$this->mMode = $mode;
	}

	function getName() {
		return 'ApprovedRevs';
	}

	function isExpensive() { return false; }

	function isSyndicated() { return false; }

	function getPageHeader() {

		// show the names of the four lists of pages, with the one
		// corresponding to the current "mode" not being linked
		$navLinks = [];
		foreach ( $this->mHeaderLinks as $msg => $queryParam ) {
			$navLinks[] = $this->createHeaderLink( $msg, $queryParam );
		}

		$navLine = wfMessage( 'approvedrevs-view' )->text() . ' ' . implode(' | ', $navLinks);
		$header = Xml::tags( 'p', null, $navLine ) . "\n";

		if ( $this->mMode == 'invalid' || $this->mMode == 'invalidfiles' ) {
			$header .= Xml::tags(
				'p', array( 'style' => 'font-style:italic;' ),
				wfMessage( 'approvedrevs-invalid-description' )->parse()
			);
		}
		return Xml::tags(
			'div', array( 'class' => 'specialapprovedrevs-header' ), $header
		);

	}

	/**
	 * Generate links for header. For current mode, generate non-link bold text.
	 */
	public function createHeaderLink( $msg, $queryParam ) {
		$approvedPagesTitle = SpecialPage::getTitleFor( $this->getName() );
		if ( $this->mMode == $queryParam ) {
			return Xml::element( 'strong',
				null,
				wfMessage( $msg )->text()
			);
		} else {
			$show = ( $queryParam == '' ) ? array() : array( 'show' => $queryParam );
			return Xml::element( 'a',
				array( 'href' => $approvedPagesTitle->getLocalURL( $show ) ),
				wfMessage( $msg )->text()
			);
		}
	}

	/**
	 * Set parameters for standard navigation links.
	 * i.e. Applies mode to next/prev links when paging through list, etc.
	 */
	function linkParameters() {
		// optionally could validate $this->mMode against $this->mHeaderLinks
		return $this->mMode == '' ? array() : array( 'show' => $this->mMode );
	}

	function getPageFooter() {
	}

	public static function getNsConditionPart( $ns ) {
		return 'p.page_namespace = ' . $ns;
	}

	function getQueryInfo() {

		// SQL for page revision approvals versus file revision approvals is
		// significantly different. Easier to follow if broken into two functions.
		if ( in_array(
			$this->mMode,
			array( 'notlatestfiles', 'unapprovedfiles', 'allfiles', 'invalidfiles' )
		) ) {
			return $this->getQueryInfoFileApprovals();
		}
		else {
			return $this->getQueryInfoPageApprovals();
		}
	}

	/**
	 * (non-PHPdoc)
	 * @see QueryPage::getSQL()
	 */
	public function getQueryInfoPageApprovals() {
		$approvedRevsNamespaces = ApprovedRevs::getApprovableNamespaces();

		$mainCondsString = "( pp_propname = 'approvedrevs' AND pp_value = 'y' " .
			"OR pp_propname = 'approvedrevs-approver-users' " .
			"OR pp_propname = 'approvedrevs-approver-groups' )";
		if ( $this->mMode == 'invalid' ) {
			$mainCondsString = "( pp_propname IS NULL OR NOT $mainCondsString )";
		}
		if ( count( $approvedRevsNamespaces ) > 0 ) {
			if ( $this->mMode == 'invalid' ) {
				$mainCondsString .= " AND ( p.page_namespace NOT IN ( " . implode( ',', $approvedRevsNamespaces ) . " ) )";
			} else {
				$mainCondsString .= " OR ( p.page_namespace IN ( " . implode( ',', $approvedRevsNamespaces ) . " ) )";
			}
		}

		if ( $this->mMode == 'all' ) {
			return array(
				'tables' => array(
					'ar' => 'approved_revs',
					'p' => 'page',
					'pp' => 'page_props',
				),
				'fields' => array(
					'p.page_id AS id',
					'ar.rev_id AS rev_id',
					'p.page_latest AS latest_id',
				),
				'join_conds' => array(
					'p' => array(
						'JOIN', 'ar.page_id=p.page_id'
					),
					'pp' => array(
						'LEFT OUTER JOIN', 'ar.page_id=pp_page'
					),
				),
				'conds' => $mainCondsString,
				'options' => array( 'DISTINCT' )
			);
		} elseif ( $this->mMode == 'unapproved' ) {
			return array(
				'tables' => array(
					'ar' => 'approved_revs',
					'p' => 'page',
					'pp' => 'page_props',
				),
				'fields' => array(
					'p.page_id AS id',
					'p.page_latest AS latest_id'
				),
				'join_conds' => array(
					'ar' => array(
						'LEFT OUTER JOIN', 'p.page_id=ar.page_id'
					),
					'pp' => array(
						'LEFT OUTER JOIN', 'ar.page_id=pp_page'
					),
				),
				'conds' => "ar.page_id IS NULL AND ( $mainCondsString )",
				'options' => array( 'DISTINCT' )
			);
		} elseif ( $this->mMode == 'invalid' ) {
			return array(
				'tables' => array(
					'ar' => 'approved_revs',
					'p' => 'page',
					'pp' => 'page_props',
				),
				'fields' => array(
					'p.page_id AS id',
					'p.page_latest AS latest_id'
				),
				'join_conds' => array(
					'p' => array(
						'LEFT OUTER JOIN', 'p.page_id=ar.page_id'
					),
					'pp' => array(
						'LEFT OUTER JOIN', 'ar.page_id=pp_page'
					),
				),
				'conds' => $mainCondsString,
				'options' => array( 'DISTINCT' )
			);
		} else { // 'approved revision is not latest'
			return array(
				'tables' => array(
					'ar' => 'approved_revs',
					'p' => 'page',
					'pp' => 'page_props',
				),
				'fields' => array(
					'p.page_id AS id',
					'ar.rev_id AS rev_id',
					'p.page_latest AS latest_id',
				),
				'join_conds' => array(
					'p' => array(
						'JOIN', 'ar.page_id=p.page_id'
					),
					'pp' => array(
						'LEFT OUTER JOIN', 'ar.page_id=pp_page'
					),
				),
				'conds' => "p.page_latest != ar.rev_id AND ( $mainCondsString )",
				'options' => array( 'DISTINCT' )
			);
		}
	}

	/**
	 * (non-PHPdoc)
	 * @see QueryPage::getSQL()
	 */
	public function getQueryInfoFileApprovals() {

		$tables = array(
			'ar' => 'approved_revs_files',
			'im' => 'image',
			'p' => 'page',
			'pp' => 'page_props',
		);

		$fields = array(
			'im.img_name AS title',
			'ar.approved_sha1 AS approved_sha1',
			'ar.approved_timestamp AS approved_ts',
			'im.img_sha1 AS latest_sha1',
			'im.img_timestamp AS latest_ts',
		);

		$conds = array();

		$join_conds = array(
			'im' => array( 'LEFT OUTER JOIN', 'ar.file_title=im.img_name' ),
			'p'  => array( 'LEFT OUTER JOIN', 'im.img_name=p.page_title' ),
			'pp' => array( 'LEFT OUTER JOIN', 'ar.page_id=pp_page' ),
		);

		$pagePropsConditions = "( (pp_propname = 'approvedrevs' AND pp_value = 'y') " .
			"OR pp_propname = 'approvedrevs-approver-users' " .
			"OR pp_propname = 'approvedrevs-approver-groups' )";

		#
		#	ALLFILES: list all approved pages
		#   also includes $this->mMode == 'invalid', see formatResult()
		#
		if ( $this->mMode == 'allapproved' ) {

			// get everything from approved_revs table
			$conds['p.page_namespace'] = NS_FILE;

		#
		#	UNAPPROVED
		#
		} elseif ( $this->mMode == 'unapproved' ) {

			$join_conds['im'] = array( 'RIGHT OUTER JOIN', 'ar.file_title=im.img_name' );

			$approvedRevsNamespaces = ApprovedRevs::getApprovableNamespaces();

			// if all files are not approvable then need to find files matching
			// __APPROVEDREVS__ and {{#approvable_by: ... }} permissions
			if ( ! in_array( NS_FILE, $approvedRevsNamespaces ) ) {
				$conds[] = $pagePropsConditions;
			}

			$conds['ar.file_title'] = null;
			$conds['p.page_namespace'] = NS_FILE;

		#
		#	INVALID PERMISSIONS
		#
		} elseif ( $this->mMode == 'invalid' ) {

			$tables['c'] = 'categorylinks';
			$join_conds['c'] = array( 'LEFT OUTER JOIN', 'p.page_id=c.cl_from' );
			$join_conds['im'] = array( 'LEFT OUTER JOIN', 'ar.file_title=im.img_name' );

			$approvedRevsNamespaces = ApprovedRevs::getApprovableNamespaces();

			if ( in_array( NS_FILE, $approvedRevsNamespaces ) ) {

				// if all files are approvable, no files should have invalid
				// approvals. Below is an impossible condition that prevents any
				// results from being returned.
				$conds[] = 'p.page_namespace=1 AND p.page_namespace=2';
			}
			else {

				$conds[] = "( pp_propname IS NULL OR NOT $pagePropsConditions )";;

			}

			$conds['p.page_namespace'] = NS_FILE;

		#
		#	NOTLATEST
		#
		} else {

			// Name/Title both exist, sha1's don't match OR timestamps
			// don't match
			$conds['p.page_namespace'] = NS_FILE;
			$conds[] = "(ar.approved_sha1!=im.img_sha1 OR ar.approved_timestamp!=im.img_timestamp)";

		}

		return [
			'tables' => $tables,
			'fields' => $fields,
			'join_conds' => $join_conds,
			'conds' => $conds,
			'options' => array( 'DISTINCT' ),
		];

	}

	function getOrder() {
		return ' ORDER BY p.page_namespace, p.page_title ASC';
	}

	function getOrderFields() {
		return array( 'p.page_namespace', 'p.page_title' );
	}

	function sortDescending() {
		return false;
	}

	function formatResult( $skin, $result ) {
		// SQL for page revision approvals versus file revision approvals is
		// significantly different. Easier to follow if broken into two functions.
		if ( in_array(
			$this->mMode,
			array( 'notlatestfiles', 'unapprovedfiles', 'allfiles', 'invalidfiles' )
		) ) {
			return $this->formatResultFileApprovals();
		}
		else {
			return $this->formatResultPageApprovals();
		}
	}

	function formatResultPageApprovals( $skin, $result ) {
		$title = Title::newFromId( $result->id );

		if( !ApprovedRevs::pageIsApprovable( $title ) && $this->mMode !== 'invalid' ) {
			return false;
		}

		$context = $skin->getContext();
		$user = $context->getUser();
		$out = $context->getOutput();
		$lang = $context->getLanguage();

		if ( method_exists( $this, 'getLinkRenderer' ) ) {
			$linkRenderer = $this->getLinkRenderer();
		} else {
			$linkRenderer = null;
		}

		// Create page link - special handling for redirects.
		$params = array();
		if ( $title->isRedirect() ) {
			$params['redirect'] = 'no';
		}
		$pageLink = ApprovedRevs::makeLink( $linkRenderer, $title, null, array(), $params );
		if ( $title->isRedirect() ) {
			$pageLink = "<em>$pageLink</em>";
		}

		if ( $this->mMode == 'all' ) {
			$additionalInfo = Xml::element( 'span',
				array (
					'class' => $result->rev_id == $result->latest_id ? 'approvedRevIsLatest' : 'approvedRevNotLatest'
				),
				wfMessage( 'approvedrevs-revisionnumber', $result->rev_id )->text()
			);

			// Get data on the most recent approval from the
			// 'approval' log, and display it if it's there.
			$loglist = new LogEventsList( $out->getSkin(), $out );
			$pager = new LogPager( $loglist, 'approval', '', $title->getText() );
			$pager->mLimit = 1;
			$pager->doQuery();
			$row = $pager->mResult->fetchObject();

			if ( !empty( $row ) ) {
				$timestamp = $lang->timeanddate( wfTimestamp( TS_MW, $row->log_timestamp ), true );
				$date = $lang->date( wfTimestamp( TS_MW, $row->log_timestamp ), true );
				$time = $lang->time( wfTimestamp( TS_MW, $row->log_timestamp ), true );
				$userLink = Linker::userLink( $row->log_user, $row->user_name );
				$additionalInfo .= ', ' . wfMessage(
					'approvedrevs-approvedby',
					$userLink,
					$timestamp,
					$row->user_name,
					$date,
					$time
				)->text();
			}

			return "$pageLink ($additionalInfo)";
		} elseif ( $this->mMode == 'unapproved' ) {
			global $egApprovedRevsShowApproveLatest;

			$line = $pageLink;
			if ( $egApprovedRevsShowApproveLatest &&
				ApprovedRevs::checkPermission( $user, $title, 'approverevisions' ) ) {
				$line .= ' (' . Xml::element( 'a',
					array( 'href' => $title->getLocalUrl(
						array(
							'action' => 'approve',
							'oldid' => $result->latest_id
						)
					) ),
					wfMessage( 'approvedrevs-approvelatest' )->text()
				) . ')';
			}

			return $line;
		} elseif ( $this->mMode == 'invalid' ) {
			return $pageLink;
		} else { // approved revision is not latest
			$diffLink = Xml::element( 'a',
				array( 'href' => $title->getLocalUrl(
					array(
						'diff' => $result->latest_id,
						'oldid' => $result->rev_id
					)
				) ),
				wfMessage( 'approvedrevs-difffromlatest' )->text()
			);

			return "$pageLink ($diffLink)";
		}
	}

	public function formatResultFileApprovals( $skin, $result ) {

		$title = Title::makeTitle( NS_FILE, $result->title );

		if ( ! self::$repo ) {
			self::$repo = RepoGroup::singleton();
		}

		$pageLink = Linker::link( $title );


		#
		#	Unapproved Files
		#
		if ( $this->mMode == 'unapproved' ) {
			global $egApprovedRevsShowApproveLatest;

			if ( $egApprovedRevsShowApproveLatest && ApprovedRevs::userCanApprove( $title ) ) {
				$approveLink = ' (' . Xml::element(
					'a',
					array(
						'href' => $title->getLocalUrl(
							array(
								'action' => 'approvefile',
								'ts' => $result->latest_ts,
								'sha1' => $result->latest_sha1
							)
						)
					),
					wfMessage( 'approvedrevs-approve' )->text()
				) . ')';
			}
			else {
				$approveLink = '';
			}

			return "$pageLink$approveLink";

		#
		#   Invalid Files
		#
		} elseif ( $this->mMode == 'invalid' ) {

			if ( ! ApprovedRevs::fileIsApprovable( $title ) ) {
				// if showing invalid files only, don't show files
				// that have real approvability
				return '';
			}

			return $pageLink;

		#
		#	All Files with an approved revision
		#
			// main mode (pages with an approved revision)
		} elseif ( $this->mMode == 'allapproved' ) {
			global $wgUser, $wgOut, $wgLang;

			$additionalInfo = Xml::element( 'span',
				array(
					'class' =>
						( $result->approved_sha1 == $result->latest_sha1
							&& $result->approved_ts == $result->latest_ts
						) ? 'approvedRevIsLatest' : 'approvedRevNotLatest'
				),
				wfMessage(
					'approvedrevs-revisionnumber',
					substr( $result->approved_sha1, 0, 8 )
				)->parse()
			);

			// Get data on the most recent approval from the
			// 'approval' log, and display it if it's there.
			$loglist = new LogEventsList( $skin, $wgOut );
			$pager = new LogPager( $loglist, 'approval', '', $title );
			$pager->mLimit = 1;
			$pager->doQuery();

			$result = $pager->getResult();
			$row = $result->fetchObject();


			if ( ! empty( $row ) ) {
				$timestamp = $wgLang->timeanddate(
					wfTimestamp( TS_MW, $row->log_timestamp ), true
				);
				$date = $wgLang->date(
					wfTimestamp( TS_MW, $row->log_timestamp ), true
				);
				$time = $wgLang->time(
					wfTimestamp( TS_MW, $row->log_timestamp ), true
				);
				$userLink = $skin->userLink( $row->log_user, $row->user_name );
				$additionalInfo .= ', ' . wfMessage(
					'approvedrevs-approvedby',
					$userLink,
					$timestamp,
					$row->user_name,
					$date,
					$time
				)->text();
			}

			return "$pageLink ($additionalInfo)";

		#
		# Not Latest Files:
		# [[My File.jpg]] (revision 2ba82e7f approved; revision 6ac914dc latest)
		} else {

			$approved_file = self::$repo->findFileFromKey(
				$result->approved_sha1,
				array( 'time' => $result->approved_ts )
			);
			$latest_file = self::$repo->findFileFromKey(
				$result->latest_sha1,
				array( 'time' => $result->latest_ts )
			);

			$approvedLink = Xml::element( 'a',
				array( 'href' => $approved_file->getUrl() ),
				wfMessage( 'approvedrevs-approvedfile' )->text()
			);
			$latestLink = Xml::element( 'a',
				array( 'href' => $latest_file->getUrl() ),
				wfMessage( 'approvedrevs-latestfile' )->text()
			);

			return "$pageLink ($approvedLink | $latestLink)";
		}

	}

}
