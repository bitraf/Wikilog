<?php
/**
 * MediaWiki Wikilog extension
 * Copyright © 2008-2010 Juliano F. Ravasi
 * http://www.mediawiki.org/wiki/Extension:Wikilog
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

/**
 * @file
 * @ingroup Extensions
 * @author Juliano F. Ravasi < dev juliano info >
 */

if ( !defined( 'MEDIAWIKI' ) )
	die();

/**
 * Wikilog SQL query driver base class.
 */
abstract class WikilogQuery
{
	/**
	 * Array of defined options. Options are set with setOption(), and cause
	 * changes to the resulting SQL query generated by the class.
	 */
	protected $mOptions = array();

	/**
	 * Default options. This array should be overriden by derived classes.
	 */
	protected $mDefaultOptions = array();

	/**
	 * Whether the query should always return nothing (when invalid options
	 * are provided, for example).
	 */
	protected $mEmpty = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
	}

	/**
	 * Get option. Return default value if not set.
	 * @param $key string  Name of the option to get.
	 * @return mixed  Current value of the option.
	 */
	public function getOption( $key ) {
		return isset( $this->mOptions[$key] )
			? $this->mOptions[$key]
			: $this->mDefaultOptions[$key];
	}

	/**
	 * Set option.
	 * @param $key string  Name of the option to set.
	 * @param $value mixed  Target value.
	 */
	public function setOption( $key, $value = true ) {
		$this->mOptions[$key] = $value;
	}

	/**
	 * Set options.
	 * @param $opts mixed  Options to set (string or array).
	 */
	public function setOptions( $opts ) {
		if ( is_string( $opts ) ) {
			$this->mOptions[$opts] = true;
		} elseif ( is_array( $opts ) ) {
			$this->mOptions = array_merge( $this->mOptions, $opts );
		} elseif ( !is_null( $opts ) ) {
			throw new MWException( __METHOD__ . ': Invalid $opts parameter.' );
		}
	}

	/**
	 * Filter is always returns empty.
	 */
	public function setEmpty( $empty = true ) { $this->mEmpty = $empty; }
	public function getEmpty() { return $this->mEmpty; }

	/**
	 * Generate and return query information.
	 * @param $db Database  Database object used to encode table names, etc.
	 * @param $opts mixed  Misc query options.
	 * @return Associative array with the following keys:
	 *   'fields' => array of fields to query
	 *   'tables' => array of tables to query
	 *   'conds' => mixed array with select conditions
	 *   'options' => associative array of options
	 *   'join_conds' => associative array of table join conditions
	 * @see Database::select() for details.
	 */
	abstract function getQueryInfo( $db, $opts = null );

	/**
	 * Return query information as an array of CGI parameters.
	 * @return array  Array of query parameters.
	 */
	abstract function getDefaultQuery();

	/**
	 * Convert a date tuple to a timestamp interval for database queries.
	 *
	 * @param $year Year to query. Current year is assumed if zero or false.
	 * @param $month Month to query. The whole year is assumed if zero or false.
	 * @param $day Day to query. The whole month is assumed if zero or false.
	 * @return Two-element array with the minimum and maximum values to query.
	 */
	public static function partialDateToInterval( &$year, &$month, &$day ) {
		$year  = ( $year  > 0 && $year  <= 9999 ) ? $year  : false; // Y10k bug :-P
		$month = ( $month > 0 && $month <=   12 ) ? $month : false;
		$day   = ( $day   > 0 && $day   <=   31 ) ? $day   : false;

		if ( !$year && !$month )
			return false;

		if ( !$year ) {
			$year = intval( gmdate( 'Y' ) );
			if ( $month > intval( gmdate( 'n' ) ) ) $year--;
		}

		$date_end = str_pad( $year + 1, 4, '0', STR_PAD_LEFT );
		$date_start = str_pad( $year, 4, '0', STR_PAD_LEFT );
		if ( $month ) {
			$date_end = $date_start . str_pad( $month + 1, 2, '0', STR_PAD_LEFT );
			$date_start = $date_start . str_pad( $month, 2, '0', STR_PAD_LEFT );
			if ( $day ) {
				$date_end = $date_start . str_pad( $day + 1, 2, '0', STR_PAD_LEFT );
				$date_start = $date_start . str_pad( $day, 2, '0', STR_PAD_LEFT );
			}
		}

		return array(
			str_pad( $date_start, 14, '0', STR_PAD_RIGHT ),
			str_pad( $date_end,   14, '0', STR_PAD_RIGHT )
		);
	}

	/**
	 * Does $dbr->select with additional conditions taken from this query object
	 */
	public function select( $dbr, $tables, $fields, $conds = array(),
		$function = __METHOD__, $options = array(), $join_conds = array()) {
		return $dbr->query( $this->selectSQLText( $dbr, $tables, $fields, $conds, $function, $options, $join_conds ), $function );
	}

	/**
	 * Generates query text with additional conditions taken from this query object
	 */
	public function selectSQLText( $dbr, $tables, $fields, $conds = array(),
		$function = __METHOD__, $options = array(), $join_conds = array() ) {
		$info = $this->getQueryInfo( $dbr );
		if ( $tables ) {
			$tables = array_merge( $info['tables'], $tables );
		} else {
			$tables = $info['tables'];
		}
		if ( $info['conds'] ) {
			$conds = $conds ? array_merge( $info['conds'], $conds ) : $info['conds'];
		}
		if ( $info['options'] ) {
			$options = $options ? array_merge( $info['options'], $options ) : $info['options'];
		}
		if ( $info['join_conds'] ) {
			$join_conds = $join_conds ? array_merge( $info['join_conds'], $join_conds ) : $info['join_conds'];
		}
		if ( !$fields ) {
			$fields = $info['fields'];
		}
		return $dbr->selectSQLText($tables, $fields, $conds, $function, $options, $join_conds);
	}
}

/**
 * Wikilog item SQL query driver.
 * This class drives queries for wikilog items, given the fields to filter.
 */
class WikilogItemQuery
	extends WikilogQuery
{
	# Valid filter values for publish status.
	const PS_ALL       = 0;		///< Return all items
	const PS_PUBLISHED = 1;		///< Return only published items
	const PS_DRAFTS    = 2;		///< Return only drafts

	# Local variables.
	private $mWikilogTitle = null;			///< Filter by wikilog.
	private $mNamespace = false;			///< Filter by namespace.
	private $mPubStatus = self::PS_ALL;		///< Filter by published status.
	private $mCategory = false;				///< Include items in this category,
											///  Include items in blogs blogs in this category.
	private $mNotCategory = false;			///< Exclude items belonging to this category,
											///  exclude items belonging to blog in this category.
	private $mAuthor = false;				///< Filter by author.
	private $mTag = false;					///< Filter by tag.
	private $mDate = false;					///< Filter by date.
	private $mNeedWikilogParam = false;		///< Need wikilog param in queries.

	# Options
	/** Query options. */
	protected $mDefaultOptions = array(
		'last-visit-date' => false
	);

	/**
	 * Constructor. Creates a new instance and optionally sets the Wikilog
	 * title to query.
	 * @param $wikilogTitle Wikilog title object to query for.
	 */
	public function __construct( $wikilogTitle = null ) {
		parent::__construct();

		$this->setWikilogTitle( $wikilogTitle );

		# If constructed without a title (from Special:Wikilog), it means that
		# the listing is global, and needs wikilog parameter to filter.
		$this->mNeedWikilogParam = ( $wikilogTitle == null );
	}

	/**
	 * Sets the wikilog title to query for.
	 * @param $wikilogTitle Wikilog title object to query for.
	 */
	public function setWikilogTitle( $wikilogTitle ) {
		$this->mWikilogTitle = $wikilogTitle;
	}

	/**
	 * Sets the wikilog namespace to query for.
	 * @param $ns Namespace to query for.
	 */
	public function setNamespace( $ns ) {
		$this->mNamespace = $ns;
	}

	/**
	 * Sets the publish status to query for.
	 * @param $pubStatus Publish status, string or integer.
	 */
	public function setPubStatus( $pubStatus ) {
		if ( is_null( $pubStatus ) ) {
			$pubStatus = self::PS_PUBLISHED;
		} elseif ( is_string( $pubStatus ) ) {
			$pubStatus = self::parsePubStatusText( $pubStatus );
		}
		$this->mPubStatus = intval( $pubStatus );
	}

	/**
	 * Sets the category to query for.
	 * @param $category Category title object or text.
	 */
	public function setCategory( $category ) {
		if ( is_null( $category ) || is_object( $category ) ) {
			$this->mCategory = $category;
		} elseif ( is_string( $category ) ) {
			$t = Title::makeTitleSafe( NS_CATEGORY, $category );
			if ( $t !== null ) {
				$this->mCategory = $t;
			}
		}
	}

	/**
	 * Sets the category not to query for.
	 * @param $category Category title object or text.
	 */
	public function setNotCategory( $category ) {
		if ( is_object( $category ) ) {
			$this->mNotCategory = $category;
		} elseif ( is_string( $category ) ) {
			$t = Title::makeTitleSafe( NS_CATEGORY, $category );
			if ( $t !== null ) {
				$this->mNotCategory = $t;
			}
		}
	}

	/**
	 * Sets the author to query for.
	 * @param $author User page title object or text.
	 */
	public function setAuthor( $author ) {
		if ( is_null( $author ) || is_object( $author ) ) {
			$this->mAuthor = $author;
		} elseif ( is_string( $author ) ) {
			$t = Title::makeTitleSafe( NS_USER, $author );
			if ( $t !== null ) {
				$this->mAuthor = User::getCanonicalName( $t->getText() );
			}
		}
	}

	/**
	 * Sets the tag to query for.
	 * @param $tag Tag text.
	 */
	public function setTag( $tag ) {
		global $wgWikilogEnableTags;
		if ( $wgWikilogEnableTags ) {
			$this->mTag = $tag;
		}
	}

	/**
	 * Sets the date to query for.
	 * @param $year Publish date year.
	 * @param $month Publish date month, optional. If ommited, queries for
	 *   items during the whole year.
	 * @param $day Publish date day, optional. If ommited, queries for items
	 *   during the whole month or year.
	 */
	public function setDate( $year, $month = false, $day = false ) {
		$interval = self::partialDateToInterval( $year, $month, $day );
		if ( $interval ) {
			list( $start, $end ) = $interval;
			$this->mDate = (object)array(
				'year'  => $year,
				'month' => $month,
				'day'   => $day,
				'start' => $start,
				'end'   => $end
			);
		}
	}

	/**
	 * Accessor functions.
	 */
	public function getWikilogTitle()	{ return $this->mWikilogTitle; }
	public function getNamespace() { return $this->mNamespace; }
	public function getPubStatus()		{ return $this->mPubStatus; }
	public function getCategory()		{ return $this->mCategory; }
	public function getNotCategory()	{ return $this->mNotCategory; }
	public function getAuthor()		{ return $this->mAuthor; }
	public function getTag()			{ return $this->mTag; }
	public function getDate()			{ return $this->mDate; }

	/**
	 * Organizes all the query information and constructs the table and
	 * field lists that will later form the SQL SELECT statement.
	 * @param $db Database object.
	 * @param $opts Array with query options. Keys are option names, values
	 *   are option values. Valid options are:
	 *   - 'last-comment-timestamp': If true, the most recent article comment
	 *     timestamps are included in the results. This is used in Atom feeds.
	 * @return Array with tables, fields, conditions, options and join
	 *   conditions, to be used in a call to $db->select(...).
	 */
	public function getQueryInfo( $db, $opts = array() ) {
		$this->setOptions( $opts );

		# Basic defaults.
		$wlp_tables = WikilogItem::selectTables( $db );
		$q_tables = $wlp_tables['tables'];
		$q_fields = WikilogItem::selectFields();
		$q_conds = array( 'p.page_is_redirect' => 0 );
		$q_options = array();
		$q_joins = $wlp_tables['join_conds'];

		# Invalid filter.
		if ( $this->mEmpty ) {
			$q_conds[] = '0=1';
		}

		# Filter by wikilog name.
		if ( $this->mWikilogTitle !== null ) {
			$q_conds['wlp_parent'] = $this->mWikilogTitle->getArticleId();
		} elseif ( $this->mNamespace !== false ) {
			$q_conds['p.page_namespace'] = $this->mNamespace;
		}

		# Filter by published status.
		if ( $this->mPubStatus === self::PS_PUBLISHED ) {
			$q_conds['wlp_publish'] = 1;
		} elseif ( $this->mPubStatus === self::PS_DRAFTS ) {
			$q_conds['wlp_publish'] = 0;
		}

		# Filter by category.
		if ( $this->mCategory ) {
			# Items and blogs
			$q_tables['clyes'] = 'categorylinks';
			$q_joins['clyes'] = array( 'JOIN', '(wlp_page = clyes.cl_from OR wlp_parent = clyes.cl_from)' );
			$q_conds['clyes.cl_to'] = $this->mCategory->getDBkey();
			$q_options['GROUP BY'] = 'wlp_page';
		}

		# Exclude items and blogs belonging to category.
		if ( $this->mNotCategory ) {
			# Items
			$q_tables['clno'] = 'categorylinks';
			$q_joins['clno'] = array( 'LEFT JOIN', array( 'wlp_page = clno.cl_from', 'clno.cl_to' => $this->mNotCategory->getDBkey() ) );
			$q_conds[] = 'clno.cl_to IS NULL';
			# Blogs
			$q_tables['clnob'] = 'categorylinks';
			$q_joins['clnob'] = array( 'LEFT JOIN', array( 'wlp_parent = clnob.cl_from', 'clnob.cl_to' => $this->mNotCategory->getDBkey() ) );
			$q_conds[] = 'clnob.cl_to IS NULL';
		}

		# Filter by author.
		if ( $this->mAuthor ) {
			$q_tables[] = 'wikilog_authors';
			$q_joins['wikilog_authors'] = array( 'JOIN', 'wlp_page = wla_page' );
			$q_conds['wla_author_text'] = $this->mAuthor;
		}

		# Filter by tag.
		if ( $this->mTag ) {
			$q_tables[] = 'wikilog_tags';
			$q_joins['wikilog_tags'] = array( 'JOIN', 'wlp_page = wlt_page' );
			$q_conds['wlt_tag'] = $this->mTag;
		}

		# Filter by date.
		if ( $this->mDate ) {
			$q_conds[] = 'wlp_pubdate >= ' . $db->addQuotes( $this->mDate->start );
			$q_conds[] = 'wlp_pubdate < ' . $db->addQuotes( $this->mDate->end );
		}

		# Last visit date
		global $wgUser;
		if ( $this->getOption( 'last-visit-date' ) && $wgUser->getID() ) {
			$q_tables[] = 'page_last_visit';
			$q_fields[] = 'pv_date wlp_last_visit';
			$q_joins['page_last_visit'] = array( 'LEFT JOIN', array( 'pv_page = wlp_page', 'pv_user' => $wgUser->getID() ) );
		}

		return array(
			'tables' => $q_tables,
			'fields' => $q_fields,
			'conds' => $q_conds,
			'options' => $q_options,
			'join_conds' => $q_joins
		);
	}

	/**
	 * Returns the query information as an array suitable to be used to
	 * construct a URL to a wikilog or Special:Wikilog pages with the proper
	 * query parameters. Used in navigation links.
	 */
	public function getDefaultQuery() {
		$query = array();

		if ( $this->mNeedWikilogParam && $this->mWikilogTitle ) {
			$query['wikilog'] = $this->mWikilogTitle->getPrefixedDBKey();
		} elseif ( $this->mNamespace !== false ) {
			$query['wikilog'] = Title::makeTitle( $this->mNamespace, "*" )->getPrefixedDBKey();
		}

		if ( $this->mPubStatus == self::PS_ALL ) {
			$query['show'] = 'all';
		} elseif ( $this->mPubStatus == self::PS_DRAFTS ) {
			$query['show'] = 'drafts';
		}

		if ( $this->mCategory ) {
			$query['category'] = $this->mCategory->getDBKey();
		}

		if ( $this->mNotCategory ) {
			$query['notcategory'] = $this->mNotCategory->getDBKey();
		}

		if ( $this->mAuthor ) {
			$query['author'] = $this->mAuthor;
		}

		if ( $this->mTag ) {
			$query['tag'] = $this->mTag;
		}

		if ( $this->mDate ) {
			$query['year']  = $this->mDate->year;
			$query['month'] = $this->mDate->month;
			$query['day']   = $this->mDate->day;
		}

		return $query;
	}

	/**
	 * Returns whether this query object returns articles from only a single
	 * wikilog.
	 */
	public function isSingleWikilog() {
		return $this->mWikilogTitle !== null;
	}

	/**
	 * Parse a publication status text ( 'drafts', 'published', etc.) and
	 * return a self::PS_* constant that represents that status.
	 */
	public static function parsePubStatusText( $show = 'published' ) {
		if ( $show == 'all' || $show == 'any' ) {
			return self::PS_ALL;
		} elseif ( $show == 'draft' || $show == 'drafts' ) {
			return self::PS_DRAFTS;
		} else {
			return self::PS_PUBLISHED;
		}
	}
}

/**
 * Wikilog comment SQL query driver.
 * This class drives queries for wikilog comments, given the fields to filter.
 * @since Wikilog v1.1.0.
 */
class WikilogCommentQuery
	extends WikilogQuery
{
	// Valid filter values for moderation status.
	const MS_ALL        = 'all';		///< Return all comments.
	const MS_ACCEPTED   = 'accepted';	///< Return only accepted comments.
	const MS_PENDING    = 'pending';	///< Return only pending comments.
	const MS_NOTDELETED = 'notdeleted';	///< Return all but deleted comments.
	const MS_NOTPENDING = 'notpending';	///< Return all but pending comments.

	public static $modStatuses = array(
		self::MS_ALL, self::MS_ACCEPTED, self::MS_PENDING,
		self::MS_NOTDELETED, self::MS_NOTPENDING
	);

	// Local variables.
	private $mModStatus = self::MS_ALL;	///< Filter by moderation status.
	private $mNamespace = false;		///< Filter by namespace.
	private $mSubject = false;			///< Filter by subject article.
	private $mThread = false;			///< Filter by thread.
	private $mAuthor = false;			///< Filter by author.
	private $mDate = false;				///< Filter by date.
	private $mIncludeSubpages = false;	///< Include comments to all subpages of subject page.
	private $mSort = 'thread';			///< Sort order
	private $mLimit = 0;				///< Limit
	private $mFirstCommentId = 0;		///< "Offset" (ID of the first comment on page)

	// The thread boundary determined from the limit is saved here when sorting on thread.
	// "Next page" begins from comment with this ID. You can then pass it back to
	// WikilogCommentQuery using setFirstCommentId().
	public $mNextCommentId = false;
	public $mLastPost = false;
	public $mLastThread = false;

	/**
	 * Constructor.
	 * @param $from Title subject.
	 */
	public function __construct( $subject = null ) {
		parent::__construct();

		if ( $subject ) {
			$this->setSubject( $subject );
		}
	}

	/**
	 * Set the moderation status to query for.
	 * @param $modStatus Moderation status, string or integer.
	 */
	public function setModStatus( $modStatus ) {
		if ( is_null( $modStatus ) ) {
			$this->mModStatus = self::MS_ALL;
		} elseif ( in_array( $modStatus, self::$modStatuses ) ) {
			$this->mModStatus = $modStatus;
		} else {
			throw new MWException( __METHOD__ . ": Invalid moderation status." );
		}
	}

	/**
	 * Set the namespace to query for. Only comments for articles published
	 * in the given namespace are returned. The wikilog and item filters have
	 * precedence over this filter.
	 * @param $ns Namespace to query for.
	 */
	public function setNamespace( $ns ) {
		$this->mNamespace = $ns;
	}

	/**
	 * Set the page to query for. Only comments for the given article
	 * are returned. You may set includeSubpageComments() and then
	 * all comments for all subpages of this page will be also returned.
	 * @param Title $item page to query for
	 */
	public function setSubject( Title $page ) {
		$this->mSubject = $page;
	}

	/**
	 * Set the comment thread to query for. Only replies to the given thread
	 * is returned. This is intended to be used together with setItem(), in
	 * order to use the proper database index (see the wlc_post_thread index).
	 * @param $thread Thread path identifier to query for (array or string).
	 */
	public function setThread( $thread ) {
		if ( is_array( $thread ) ) {
			$thread = implode( '/', $thread );
		}
		$this->mThread = $thread;
	}

	/**
	 * Set sort order and limit.
	 * Note that the query sorted on thread ALWAYS includes full threads
	 * (threads are not broken)
	 */
	public function setLimit( $sort, $limit ) {
		$this->mSort = $sort;
		$this->mLimit = $limit;
	}

	/**
	 * Sets the author to query for.
	 * @param $author User page title object or text.
	 */
	public function setAuthor( $author ) {
		if ( is_null( $author ) || is_object( $author ) ) {
			$this->mAuthor = $author;
		} elseif ( is_string( $author ) ) {
			$t = Title::makeTitleSafe( NS_USER, $author );
			if ( $t !== null ) {
				$this->mAuthor = User::getCanonicalName( $t->getText() );
			}
		}
	}

	/**
	 * Set the date to query for.
	 * @param $year Comment year.
	 * @param $month Comment month, optional. If ommited, look for comments
	 *   during the whole year.
	 * @param $day Comment day, optional. If ommited, look for comments
	 *   during the whole month or year.
	 */
	public function setDate( $year, $month = false, $day = false ) {
		$interval = self::partialDateToInterval( $year, $month, $day );
		if ( $interval ) {
			list( $start, $end ) = $interval;
			$this->mDate = (object)array(
				'year'  => $year,
				'month' => $month,
				'day'   => $day,
				'start' => $start,
				'end'   => $end
			);
		}
	}

	public function setIncludeSubpageComments( $inc ) {
		$this->mIncludeSubpages = $inc;
	}

	public function setFirstCommentId( $id ) {
		$this->mFirstCommentId = $id;
	}

	/**
	 * Accessor functions.
	 */
	public function getModStatus() { return $this->mModStatus; }
	public function getNamespace() { return $this->mNamespace; }
	public function getSubject() { return $this->mSubject; }
	public function getThread() { return $this->mThread; }
	public function getAuthor() { return $this->mAuthor; }
	public function getDate() { return $this->mDate; }
	public function getLimit() { return $this->mLimit; }
	public function getIncludeSubpageComments() { return $this->mIncludeSubpages; }
	public function getFirstCommentId() { return $this->mFirstCommentId; }

	/**
	 * Organizes all the query information and constructs the table and
	 * field lists that will later form the SQL SELECT statement.
	 * @param $db Database object.
	 * @param $opts Array with query options. Keys are option names, values
	 *   are option values.
	 * @return Array with tables, fields, conditions, options and join
	 *   conditions, to be used in a call to $db->select(...).
	 */
	public function getQueryInfo( $db, $opts = array() ) {
		$this->setOptions( $opts );

		# Basic defaults.
		$wlc_tables = WikilogComment::selectTables();
		$q_tables = $wlc_tables['tables'];
		$q_fields = WikilogComment::selectFields();
		$q_conds = array();
		$q_options = array();
		$q_joins = $wlc_tables['join_conds'];

		# Invalid filter.
		if ( $this->mEmpty ) {
			$q_conds[] = '0=1';
		}

		# Filter by moderation status.
		if ( $this->mModStatus == self::MS_ACCEPTED ) {
			$q_conds['wlc_status'] = 'OK';
		} elseif ( $this->mModStatus == self::MS_PENDING ) {
			$q_conds['wlc_status'] = 'PENDING';
		} elseif ( $this->mModStatus == self::MS_NOTDELETED ) {
			$q_conds[] = "wlc_status <> " . $db->addQuotes( 'DELETED' );
		} elseif ( $this->mModStatus == self::MS_NOTPENDING ) {
			$q_conds[] = "wlc_status <> " . $db->addQuotes( 'PENDING' );
		}

		# Filter by subject page.
		if ( $this->mSubject ) {
			if ( $this->mIncludeSubpages ) {
				$q_conds['p.page_namespace'] = $this->mSubject->getNamespace();
				$q_conds[] = '(p.page_title = ' . $db->addQuotes( $this->mSubject->getDBkey() ) . ' OR p.page_title ' .
					$db->buildLike( $this->mSubject->getDBkey() . '/', $db->anyString() ) . ')';
			} else {
				$q_conds['wlc_post'] = $this->mSubject->getArticleId();
				if ( $this->mThread ) {
					$q_conds[] = 'wlc_thread ' . $db->buildLike( $this->mThread . '/', $db->anyString() );
				}
			}
		} elseif ( $this->mNamespace !== false ) {
			$q_conds['c.page_namespace'] = $this->mNamespace;
		}

		# Filter by author.
		if ( $this->mAuthor ) {
			$q_conds['wlc_user_text'] = $this->mAuthor;
		}

		# Filter by date.
		if ( $this->mDate ) {
			$q_conds[] = 'wlc_timestamp >= ' . $db->addQuotes( $this->mDate->start );
			$q_conds[] = 'wlc_timestamp < ' . $db->addQuotes( $this->mDate->end );
		}

		# Sort order and limits
		if ( $this->mSort == 'thread' ) {
			$dbr = wfGetDB( DB_SLAVE );
			$q_options['ORDER BY'] = 'wlc_post, wlc_thread, wlc_id';
			if ( $this->mFirstCommentId ) {
				$res = $dbr->select( 'wikilog_comments', 'wlc_post, wlc_thread',
					array( 'wlc_id' => $this->mFirstCommentId ), __METHOD__ );
				$row = $dbr->fetchObject( $res );
				if ( $row ) {
					$q_conds[] = "wlc_post >= {$row->wlc_post} AND (wlc_post > {$row->wlc_post}".
						" OR wlc_thread >= ".$dbr->addQuotes( $row->wlc_thread ).")";
				}
			}
			if ( $this->mLimit > 0 ) {
				// Determine thread boundary from limit
				$res = $dbr->select( $q_tables, 'wlc_post, wlc_thread', $q_conds,
					__METHOD__, $q_options + array( 'LIMIT' => 1, 'OFFSET' => $this->mLimit-1 ), $q_joins );
				$row = $dbr->fetchObject( $res );
				if ( $row ) {
					$p = !$this->mIncludeSubpages && $this->mThread ? strlen( $this->mThread ) : -1;
					$this->mLastPost = $row->wlc_post;
					$this->mLastThread = substr( $row->wlc_thread, 0, $p+7 );
					$this->mLastThread = substr( $this->mLastThread, 0, -6 ) .
						sprintf( "%06d", 1 + substr( $this->mLastThread, -6 ) );
					$this->mNextCommentId = $dbr->selectField( $q_tables, 'wlc_id', $q_conds + array(
							"wlc_post >= {$this->mLastPost} AND ( wlc_post > {$this->mLastPost}".
							" OR wlc_thread >= ".$dbr->addQuotes( $this->mLastThread )." )"
						), __METHOD__, array( 'LIMIT' => 1 ) );
					// Build query condition
					$lt = "wlc_thread < ".$dbr->addQuotes( $this->mLastThread );
					if ( $firstPost == $this->mLastPost ) {
						$q_conds[] = $lt;
					} else {
						$q_conds[] = "wlc_post <= {$this->mLastPost} AND (wlc_post < {$this->mLastPost} OR $lt)";
					}
				}
			}
		} else {
			$q_options['ORDER BY'] = 'wlc_id';
			if ( $this->mFirstCommentId ) {
				$q_conds[] = 'wlc_id >= '.intval( $this->mFirstCommentId );
			}
			if ( $this->mLimit > 0 ) {
				$q_options['LIMIT'] = $this->mLimit;
			}
			break;
		}

		return array(
			'tables' => $q_tables,
			'fields' => $q_fields,
			'conds' => $q_conds,
			'options' => $q_options,
			'join_conds' => $q_joins
		);
	}

	/**
	 * Returns the query information as an array suitable to be used to
	 * construct a URL to Special:WikilogComments with the proper query
	 * parameters. Used in navigation links.
	 */
	public function getDefaultQuery() {
		$query = array();

		//..............
		if ( $this->mNamespace !== false ) {
			$query['wikilog'] = Title::makeTitle( $this->mNamespace, "*" )->getPrefixedDBKey();
		}

		if ( $this->mModStatus != self::MS_ALL ) {
			$query['show'] = $this->mModStatus;
		}

		if ( $this->mAuthor ) {
			$query['author'] = $this->mAuthor;
		}

		if ( $this->mDate ) {
			$query['year']  = $this->mDate->year;
			$query['month'] = $this->mDate->month;
			$query['day']   = $this->mDate->day;
		}

		return $query;
	}

}
