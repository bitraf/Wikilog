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

# NOTE (Mw1.16- COMPAT): GAID_FOR_UPDATE removed and replaced by
# Title::GAID_FOR_UPDATE in Mw1.17. Remove this define and replace its
# occurrence WikilogCommentsPage::setCommentApproval() in Wl1.3.
if ( !defined( 'GAID_FOR_UPDATE' ) )
	define( 'GAID_FOR_UPDATE', Title::GAID_FOR_UPDATE );

/**
 * Wikilog comments namespace handler class.
 *
 * Displays a threaded discussion about a wikilog article, using its talk
 * page, replacing the mess that is the usual wiki talk pages. This allows
 * a simpler and faster interface for commenting on wikilog articles, more
 * like how traditional blogs work. It also allows other interesting things
 * that are difficult or impossible with usual talk pages, like counting the
 * number of comments for each post and generation of syndication feeds for
 * comments.
 *
 * @note This class was designed to integrate with Wikilog, and won't work
 * for the rest of the wiki. If you want a similar interface for the other
 * talk pages, you may want to check LiquidThreads or some other extension.
 */
class WikilogCommentsPage
	extends Article
	implements WikilogCustomAction
{
	protected $mSkin;				///< Skin used when rendering the page.
	protected $mFormatter;			///< Comment formatter.
	protected $mFormOptions;		///< Post comment form fields.
	protected $mUserCanPost;		///< User is allowed to post.
	protected $mUserCanModerate;	///< User is allowed to moderate.
	protected $mPostedComment;		///< Posted comment, from HTTP post data.
	protected $mCaptchaForm;		///< Captcha form fields, when saving comment.
	protected $mTrailing;			///< Trailing text in comments title page.

	public    $mItem;				///< Wikilog item the page is associated with.
	public    $mTalkTitle;			///< Main talk page title.
	public    $mSingleComment;		///< Used when viewing a single comment.

	/**
	 * Constructor.
	 *
	 * @param $title Title of the page.
	 * @param $wi WikilogInfo object with information about the wikilog and
	 *   the item.
	 */
	function __construct( Title &$title, WikilogInfo &$wi ) {
		global $wgUser, $wgRequest;

		parent::__construct( $title );

		# Check if user can post.
		$this->mUserCanPost = $wgUser->isAllowed( 'wl-postcomment' ) ||
			( $wgUser->isAllowed( 'edit' ) && $wgUser->isAllowed( 'createtalk' ) );
		$this->mUserCanModerate = $wgUser->isAllowed( 'wl-moderation' );

		# Prepare the skin and the comment formatter.
		$this->mSkin = $wgUser->getSkin();
		$this->mFormatter = new WikilogCommentFormatter( $this->mSkin, $this->mUserCanPost );

		# Get item object relative to this comments page.
		$this->mItem = WikilogItem::newFromInfo( $wi );

		# Form options.
		$this->mFormOptions = new FormOptions();
		$this->mFormOptions->add( 'wlAnonName', '' );
		$this->mFormOptions->add( 'wlComment', '' );
		$this->mFormOptions->fetchValuesFromRequest( $wgRequest,
			array( 'wlAnonName', 'wlComment' ) );

		# This flags if we are viewing a single comment (subpage).
		$this->mTrailing = $wi->getTrailing();
		$this->mWikilog = $wi->getTitle();
		$this->mTalkTitle = $wi->getTalkTitle();
		if ( $this->mItem && $this->mTrailing ) {
			$this->mSingleComment =
				WikilogComment::newFromPageID( $this->mItem, $this->getID() );
		}
	}

	/**
	 * Handler for action=view requests.
	 */
	public function view() {
		global $wgRequest, $wgOut;

		if ( $wgRequest->getVal( 'diff' ) ) {
			# Ignore comments if diffing.
			return parent::view();
		}

		# Create our query object.
		$query = new WikilogCommentQuery( $this->mItem ? $this->mItem : $this->mWikilog );

		if ( ( $feedFormat = $wgRequest->getVal( 'feed' ) ) ) {
			# RSS or Atom feed requested. Ignore all other options.
			global $wgWikilogNumComments;
			$query->setModStatus( WikilogCommentQuery::MS_ACCEPTED );
			$feed = new WikilogCommentFeed( $this->mTitle, $feedFormat, $query,
				$wgRequest->getInt( 'limit', $wgWikilogNumComments ) );
			return $feed->execute();
		}

		if ( $this->mSingleComment ) {
			# Single comment view, show comment followed by its replies.
			$params = $this->mFormatter->getCommentMsgParams( $this->mSingleComment );

			# Display the comment header and other status messages.
			$wgOut->addHtml( $this->mFormatter->formatCommentHeader( $this->mSingleComment, $params ) );

			# Display talk page contents.
			parent::view();

			# Display the comment footer.
			$wgOut->addHtml( $this->mFormatter->formatCommentFooter( $this->mSingleComment, $params ) );
		} else {
			# Normal page view, show talk page contents followed by comments.
			parent::view();

			// Set a more human-friendly title to the comments page.
			// NOTE (MW1.16+): Must come after parent::view().
			// Note: Sorry for the three-level cascade of wfMsg()'s...
			$wgOut->setPageTitle( wfMsg( 'wikilog-title-comments', $this->mItem ? $this->mItem->mName : $this->mWikilog->getPrefixedText() ) );
			if ( $this->mItem )
				$wgOut->setHTMLTitle( wfMsg( 'wikilog-title-comments',
					wfMsg( 'wikilog-title-item-full',
						$this->mWikilog->getPrefixedText(), $this->mItem->mName
					) ) );
		}

		# Add a backlink to the original article.
		if ($this->mItem)
			$link = $this->mSkin->link( $this->mItem->mTitle, $this->mItem->mName );
		else
			$link = $this->mSkin->link( $this->mWikilog, $this->mWikilog->getPrefixedText() );
		$wgOut->setSubtitle( wfMsg( 'wikilog-backlink', $link ) );

		# Retrieve comments (or replies) from database and display them.
		$this->viewComments( $query );

		# Add feed links.
		$wgOut->setSyndicated();
	}

	/**
	 * Wikilog comments view. Retrieve comments from database and display
	 * them in threads.
	 */
	protected function viewComments( WikilogCommentQuery $query ) {
		global $wgOut, $wgRequest, $wgUser;

		# Prepare query and pager objects.
		$replyTo = $wgRequest->getInt( 'wlParent' );
		$pager = new WikilogCommentThreadPager( $query, $this->mFormatter );

		# Different behavior when displaying a single comment.
		if ( $this->mSingleComment ) {
			$query->setThread( $this->mSingleComment->mThread );
			$this->mFormatter->setupRootThread( $this->mSingleComment->mThread );
			$headerMsg = 'wikilog-replies';
		} else {
			$headerMsg = 'wikilog-comments';
		}

		# Insert reply comment into the thread when replying to a comment.
		if ( $this->mUserCanPost && $replyTo ) {
			$pager->setReplyTrigger( $replyTo, array( $this, 'getPostCommentForm' ) );
		}

		# Enclose all comments or replies in a div.
		$wgOut->addHtml( Xml::openElement( 'div', array( 'class' => 'wl-comments' ) ) );

		# Comments/Replies header.
		$header = Xml::tags( 'h2', array( 'id' => 'wl-comments-header' ),
			wfMsgExt( $headerMsg, array( 'parseinline' ) )
		);
		$wgOut->addHtml( $header );

		# Display comments/replies.
		$wgOut->addHtml( $pager->getBody() . $pager->getNavigationBar() );

		# Display subscribe/unsubscribe link.
		if ( $wgUser->getId() && !$this->mSingleComment )
			$wgOut->addHtml( $this->getSubscribeLink() );

		# Display "post new comment" form, if appropriate.
		if ( $this->mUserCanPost )
			$wgOut->addHtml( $this->getPostCommentForm( $this->mSingleComment ) );
		elseif ( $wgUser->isAnon() )
			$wgOut->addWikiMsg( 'wikilog-login-to-comment' );

		# Close div.
		$wgOut->addHtml( Xml::closeElement( 'div' ) );
	}

	/**
	 * Handler for action=wikilog requests.
	 * Enabled via WikilogHooks::UnknownAction() hook handler.
	 */
	public function wikilog()
	{
		global $wgOut, $wgUser, $wgRequest;

		if ( !$this->mItem || !$this->mItem->exists() ) {
			$wgOut->showErrorPage( 'wikilog-error', 'wikilog-no-such-article' );
			return;
		}

		if ( $wgRequest->getBool( 'wlActionSubscribe' ) ) {
			$s = $this->subscribe( $this->mItem->getId() ) ? 'yes' : 'no';
			$wgOut->setPageTitle( wfMsg( "wikilog-subscribed-title-$s" ) );
			$wgOut->addWikiText( wfMsgNoTrans( "wikilog-subscribed-text-$s", $this->mItem->mTitle->getPrefixedText() ) );
			return;
		}

		# Initialize a session, when an anonymous post a comment...
		if ( session_id() == '' ) {
			wfSetupSession();
		}

		if ( $wgRequest->wasPosted() ) {
			# HTTP post: either comment preview or submission.
			$this->mPostedComment = $this->getPostedComment();
			if ( $this->mPostedComment ) {
				$submit = $wgRequest->getBool( 'wlActionCommentSubmit' );
				$preview = $wgRequest->getBool( 'wlActionCommentPreview' );
				if ( $submit ) {
					if ( !$wgUser->matchEditToken( $wgRequest->getVal( 'wpEditToken' ) ) ) {
						$wgOut->wrapWikiMsg( "<div class='error'>\n$1</div>", 'wikilog-sessionfailure' );
						$preview = true;
					} elseif ( !$this->mUserCanPost ) {
						$wgOut->permissionRequired( 'wl-postcomment' );
						$preview = true;
					} else {
						return $this->postComment( $this->mPostedComment );
					}
				}
				if ( $preview ) {
					return $this->view();
				}
			}
		} else {
			# Comment moderation, actions performed to single-comment pages.
			if ( $this->mSingleComment ) {
				# Check permissions.
				$title = $this->mSingleComment->getCommentArticleTitle();
				$permerrors = $title->getUserPermissionsErrors( 'wl-moderation', $wgUser );
				if ( count( $permerrors ) > 0 ) {
					$wgOut->showPermissionsErrorPage( $permerrors );
					return;
				}
				if ( !$wgUser->matchEditToken( $wgRequest->getVal( 'wpEditToken' ) ) ) {
					$wgOut->showErrorPage( 'wikilog-error', 'sessionfailure' );
					return;
				}

				$approval = $wgRequest->getVal( 'wlActionCommentApprove' );

				# Approve or reject a pending comment.
				if ( $approval ) {
					return $this->setCommentApproval( $this->mSingleComment, $approval );
				}
			}
		}

		$wgOut->showErrorPage( 'nosuchaction', 'nosuchactiontext' );
	}

	/**
	 * Override Article::hasViewableContent() so that it doesn't return 404
	 * if the item page exists.
	 */
	public function hasViewableContent() {
		return parent::hasViewableContent() ||
			( $this->mItem !== null && $this->mItem->exists() );
	}

	/**
	 * Generates and returns a subscribe/unsubscribe link.
	 */
	public function getSubscribeLink()
	{
		global $wgScript, $wgUser;
		if ( !$wgUser->getId() || !$this->mItem || !$this->mItem->exists() )
			return '';
		$isa = array_flip( $this->mItem->mAuthors );
		if ( isset( $isa[ $wgUser->getId() ] ) )
			return wfMsgNoTrans( 'wikilog-subscribed-as-author' );
		$sub = !$this->is_subscribed( $this->mItem->getId() );
		return wfMsgNoTrans( $sub ? 'wikilog-do-subscribe' : 'wikilog-do-unsubscribe',
			$wgScript.'?'.http_build_query( array(
				'title' => $this->getTitle()->getPrefixedText(),
				'action' => 'wikilog',
				'wlActionSubscribe' => 1,
				'wl-subscribe' => $sub ? 1 : 0,
			) ) );
	}

	/* Returns:
	   1 if current user is subcribed to post $itemid comments
	   0 if unsubscribed
	   NULL if didn't care */
	public function is_subscribed( $itemid )
	{
		global $wgUser;
		$dbr = wfGetDB( DB_SLAVE );
		$r = $dbr->selectField( 'wikilog_subscriptions', 'ws_yes', array( 'ws_page' => $itemid, 'ws_user' => $wgUser->getID() ), __METHOD__ );
		if ( $r === false )
			$r = NULL;
		return $r;
	}

	/**
	 * Generates and returns a "post new comment" form for the user to fill in
	 * and submit.
	 *
	 * @param $parent If provided, generates a "post reply" form to reply to
	 *   the given comment.
	 */
	public function getPostCommentForm( $parent = null ) {
		global $wgUser, $wgTitle, $wgScript, $wgRequest;
		global $wgWikilogModerateAnonymous;

		if (!$this->mItem && !$parent)
			return '';

		$comment = $this->mPostedComment;
		$opts = $this->mFormOptions;

		$preview = '';
		$pid = $parent ? $parent->mID : null;
		if ( $comment && $comment->mParent == $pid ) {
			$check = $this->validateComment( $comment );
			if ( $check ) {
				$preview = Xml::wrapClass( wfMsg( $check ), 'mw-warning', 'div' );
			} else {
				$preview = $this->mFormatter->formatComment( $this->mPostedComment );
			}
			$header = wfMsgHtml( 'wikilog-form-preview' );
			$preview = "<b>{$header}</b>{$preview}<hr/>";
		}

		$form =
			Xml::hidden( 'title', ( $parent ? $parent->mCommentTitle : $this->getTitle()->getPrefixedText() ) ) .
			Xml::hidden( 'action', 'wikilog' ) .
			Xml::hidden( 'wpEditToken', $wgUser->editToken() ) .
			( $parent ? Xml::hidden( 'wlParent', $parent->mID ) : '' );

		$fields = array();

		if ( $wgUser->isLoggedIn() ) {
			$fields[] = array(
				wfMsg( 'wikilog-form-name' ),
				$this->mSkin->userLink( $wgUser->getId(), $wgUser->getName() )
			);
		} else {
			$loginTitle = SpecialPage::getTitleFor( 'Userlogin' );
			$loginLink = $this->mSkin->link( $loginTitle,
				wfMsgHtml( 'loginreqlink' ), array(),
				array( 'returnto' => $wgTitle->getPrefixedUrl() )
			);
			$message = wfMsg( 'wikilog-posting-anonymously', $loginLink );
			$fields[] = array(
				Xml::label( wfMsg( 'wikilog-form-name' ), 'wl-name' ),
				Xml::input( 'wlAnonName', 25, $opts->consumeValue( 'wlAnonName' ),
					array( 'id' => 'wl-name', 'maxlength' => 255 ) ) .
					"<p>{$message}</p>"
			);
		}

		$autofocus = $parent ? array( 'autofocus' => 'autofocus' ) : array();
		$fields[] = array(
			Xml::label( wfMsg( 'wikilog-form-comment' ), 'wl-comment' ),
			Xml::textarea( 'wlComment', $opts->consumeValue( 'wlComment' ),
				40, 5, array( 'id' => 'wl-comment' ) + $autofocus )
		);

		if ( $this->mCaptchaForm ) {
			$fields[] = array( '', $this->mCaptchaForm );
		}

		if ( $wgWikilogModerateAnonymous && $wgUser->isAnon() ) {
			$fields[] = array( '', wfMsg( 'wikilog-anonymous-moderated' ) );
		}

		if ( $wgUser->getID() )
		{
			$itemid = $this->mItem ? $this->mItem->getID() : $parent->mPost;
			$subscribed = $this->is_subscribed( $itemid );
			if ( $subscribed === NULL )
				$subscribed = true;
			$subscribe_html = ' &nbsp; ' . Xml::checkLabel( wfMsg( 'wikilog-subscribe' ), 'wl-subscribe', 'wl-subscribe', $subscribed );
		}

		$fields[] = array( '',
			Xml::submitbutton( wfMsg( 'wikilog-submit' ), array( 'name' => 'wlActionCommentSubmit' ) ) . WL_NBSP .
			Xml::submitbutton( wfMsg( 'wikilog-preview' ), array( 'name' => 'wlActionCommentPreview' ) ) .
			$subscribe_html
		);

		$form .= WikilogUtils::buildForm( $fields );

		foreach ( $opts->getUnconsumedValues() as $key => $value ) {
			$form .= Xml::hidden( $key, $value );
		}

		$form = Xml::tags( 'form', array(
			'action' => "{$wgScript}#wl-comment-form",
			'method' => 'post'
		), $form );

		$msgid = ( $parent ? 'wikilog-post-reply' : 'wikilog-post-comment' );
		return Xml::fieldset( wfMsg( $msgid ), $preview . $form,
			array( 'id' => 'wl-comment-form' ) ) . "\n";
	}

	/**
	 * @todo (In Wikilog 1.3.x) Replace GAID_FOR_UPDATE with
	 *    Title::GAID_FOR_UPDATE.
	 */
	protected function setCommentApproval( $comment, $approval ) {
		global $wgOut, $wgUser;

		# Check if comment is really awaiting moderation.
		if ( $comment->mStatus != WikilogComment::S_PENDING ) {
			$wgOut->showErrorPage( 'nosuchaction', 'nosuchactiontext' );
			return;
		}

		$log = new LogPage( 'wikilog' );
		$title = $comment->getCommentArticleTitle();

		if ( $approval == 'approve' ) {
			$comment->mStatus = WikilogComment::S_OK;
			$comment->saveComment();
			$log->addEntry( 'c-approv', $title, '' );
			$wgOut->redirect( $this->mTalkTitle->getFullUrl() );
		} elseif ( $approval == 'reject' ) {
			$reason = wfMsgExt( 'wikilog-log-cmt-rejdel',
				array( 'content', 'parsemag' ),
				$comment->mUserText
			);
			$id = $title->getArticleID( GAID_FOR_UPDATE );
			if ( $this->doDeleteArticle( $reason, false, $id ) ) {
				$comment->deleteComment();
				$log->addEntry( 'c-reject', $title, '' );
				$wgOut->redirect( $this->mTalkTitle->getFullUrl() );
			} else {
				$wgOut->showFatalError( wfMsgExt( 'cannotdelete', array( 'parse' ) ) );
				$wgOut->addHTML( Xml::element( 'h2', null, LogPage::logName( 'delete' ) ) );
				LogEventsList::showLogExtract( $wgOut, 'delete', $this->mTitle->getPrefixedText() );
			}
		} else {
			$wgOut->showErrorPage( 'nosuchaction', 'nosuchactiontext' );
		}
	}

	/**
	 * Subscribes/unsubscribes current user to/from comments to some post
	 */
	protected function subscribe( $page_id )
	{
		global $wgUser, $wgRequest;
		if ($wgUser->getID())
		{
			$subscribe = $wgRequest->getBool('wl-subscribe') ? 1 : 0;
			$dbw = wfGetDB(DB_MASTER);
			$dbw->replace('wikilog_subscriptions', array(array('ws_page', 'ws_user')), array(
				'ws_page' => $page_id,
				'ws_user' => $wgUser->getID(),
				'ws_yes'  => $subscribe,
				'ws_date' => wfTimestamp(TS_MW),
			), __METHOD__);
			return $subscribe;
		}
		return NULL;
	}

	/**
	 * Validates and saves a new comment. Redirects back to the comments page.
	 * @param $comment Posted comment.
	 */
	protected function postComment( WikilogComment &$comment ) {
		global $wgOut, $wgUser;
		global $wgWikilogModerateAnonymous;

		$check = $this->validateComment( $comment );

		if ( $check !== false ) {
			return $this->view();
		}

		# Check through captcha.
		if ( !WlCaptcha::confirmEdit( $this->getTitle(), $comment->getText() ) ) {
			$this->mCaptchaForm = WlCaptcha::getCaptchaForm();
			$wgOut->setPageTitle( $this->mTitle->getPrefixedText() );
			$wgOut->addHtml( $this->getPostCommentForm( $comment->mParent ) );
			return;
		}

		# Limit rate of comments.
		if ( $wgUser->pingLimiter() ) {
			$wgOut->rateLimited();
			return;
		}

		# Set pending state if moderated.
		if ( $comment->mUserID == 0 && $wgWikilogModerateAnonymous ) {
			$comment->mStatus = WikilogComment::S_PENDING;
		}

		if ( !$this->exists() ) {
			# Initialize a blank talk page.
			$user = User::newFromName( wfMsgForContent( 'wikilog-auto' ), false );
			$this->doEdit(
				wfMsgForContent( 'wikilog-newtalk-text' ),
				wfMsgForContent( 'wikilog-newtalk-summary' ),
				EDIT_NEW | EDIT_SUPPRESS_RC, false, $user
			);
		}

		$comment->saveComment();

		$this->subscribe( $comment->mPost );

		$dest = $this->getTitle();
		$dest->setFragment( "#c{$comment->mID}" );
		$wgOut->redirect( $dest->getFullUrl() );
	}

	/**
	 * Returns a new non-validated WikilogComment object with the contents
	 * posted using the post comment form. The result should be validated
	 * using validateComment() before using.
	 */
	protected function getPostedComment() {
		global $wgUser, $wgRequest;

		$parent = $wgRequest->getIntOrNull( 'wlParent' );
		$anonname = trim( $wgRequest->getText( 'wlAnonName' ) );
		$text = trim( $wgRequest->getText( 'wlComment' ) );

		$comment = WikilogComment::newFromText( $this->mItem, $text, $parent );
		$comment->setUser( $wgUser );
		if ( $wgUser->isAnon() ) {
			$comment->setAnon( $anonname );
		}
		return $comment;
	}

	/**
	 * Checks if the given comment is valid for posting.
	 * @param $comment Comment to validate.
	 * @returns False if comment is valid, error message identifier otherwise.
	 */
	protected static function validateComment( WikilogComment &$comment ) {
		global $wgWikilogMaxCommentSize;

		$length = strlen( $comment->mText );

		if ( $length == 0 ) {
			return 'wikilog-comment-is-empty';
		}
		if ( $length > $wgWikilogMaxCommentSize ) {
			return 'wikilog-comment-too-long';
		}

		if ( $comment->mUserID == 0 ) {
			$anonname = User::getCanonicalName( $comment->mAnonName, 'usable' );
			if ( !$anonname ) {
				return 'wikilog-comment-invalid-name';
			}
			$comment->setAnon( $anonname );
		}

		return false;
	}
}
