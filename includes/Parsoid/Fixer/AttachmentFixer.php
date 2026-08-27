<?php

namespace Flow\Parsoid\Fixer;

use DOMElement;
use DOMNode;
use Flow\Attachment\AttachmentStore;
use Flow\Attachment\TopicAttachment;
use Flow\Model\UUID;
use Flow\Parsoid\Fixer;
use MediaWiki\Context\RequestContext;
use MediaWiki\Title\Title;

/**
 * Post content only ever stores a plain link to Special:FlowAttachment (that
 * is what the editors insert, and it round-trips cleanly through both HTML
 * and wikitext). At render time this fixer upgrades those links: inline-safe
 * images become an actual <img>, everything else becomes an attachment card
 * showing name and size.
 */
class AttachmentFixer implements Fixer {

	private AttachmentStore $store;

	/** @var array<string,TopicAttachment|null> per-request lookup cache */
	private array $cache = [];

	public function __construct( AttachmentStore $store ) {
		$this->store = $store;
	}

	/**
	 * @return string
	 */
	public function getXPath() {
		// Matches canonical and localized Special:FlowAttachment references,
		// both as links and as inline images inserted by the editor
		return '//a[contains(@href,"FlowAttachment/")] | //img[contains(@src,"FlowAttachment/")]';
	}

	/**
	 * @param DOMNode $node
	 * @param Title $title
	 */
	public function apply( DOMNode $node, Title $title ) {
		if ( !$node instanceof DOMElement ) {
			return;
		}

		$ref = $node->nodeName === 'img' ? $node->getAttribute( 'src' ) : $node->getAttribute( 'href' );
		if ( !preg_match( '~FlowAttachment/([0-9a-z]{16,19})(?:/|$|\?)~i', $ref, $matches ) ) {
			return;
		}

		// Attachments are only ever served to registered users: for anonymous
		// viewers, replace the whole reference (name included) with a neutral
		// placeholder so neither the file name nor its URL is exposed.
		if ( !RequestContext::getMain()->getUser()->isRegistered() ) {
			$doc = $node->ownerDocument;
			$placeholder = $doc->createElement( 'span' );
			$placeholder->setAttribute( 'class', 'flow-attachment flow-attachment-placeholder' );

			$icon = $doc->createElement( 'span' );
			$icon->setAttribute( 'class', 'flow-attachment-card-icon' );
			$placeholder->appendChild( $icon );

			$placeholder->appendChild( $doc->createTextNode(
				wfMessage( 'flow-attachment-restricted' )->text()
			) );
			$node->parentNode->replaceChild( $placeholder, $node );
			return;
		}

		$attachment = $this->lookup( strtolower( $matches[1] ) );
		if ( !$attachment ) {
			// Deleted or unknown: replace with a marker, dropping any dead
			// <img> so no broken image is shown
			$doc = $node->ownerDocument;
			$missing = $doc->createElement( 'span' );
			$missing->setAttribute( 'class', 'flow-attachment-missing' );
			$missing->appendChild( $doc->createTextNode(
				$node->nodeName === 'img' ?
					wfMessage( 'flow-attachment-missing' )->text() :
					$node->textContent
			) );
			$node->parentNode->replaceChild( $missing, $node );
			return;
		}

		$doc = $node->ownerDocument;
		$url = $attachment->getUrl();

		$link = $doc->createElement( 'a' );
		$link->setAttribute( 'href', $url );
		$link->setAttribute( 'rel', 'noreferrer noopener' );

		if ( $attachment->isInlineImage() ) {
			$link->setAttribute( 'class', 'flow-attachment flow-attachment-image' );
			$link->setAttribute( 'target', '_blank' );
			$link->setAttribute( 'title', $attachment->getName() );
			$img = $doc->createElement( 'img' );
			$img->setAttribute( 'src', $url );
			$img->setAttribute( 'alt', $attachment->getName() );
			// Preserve the size the author gave the image in the editor: it
			// is carried in the reference URL's query (?w=&h=), the only
			// form that survives the wikitext storage round-trip
			foreach ( [ 'width' => 'w', 'height' => 'h' ] as $dimension => $param ) {
				if ( preg_match( '~[?&]' . $param . '=(\d+)~', $ref, $size ) ) {
					$img->setAttribute( $dimension, $size[1] );
				} elseif ( $node->nodeName === 'img' ) {
					$value = $node->getAttribute( $dimension );
					if ( $value !== '' && ctype_digit( $value ) ) {
						$img->setAttribute( $dimension, $value );
					}
				}
			}
			$link->appendChild( $img );
		} else {
			$lang = RequestContext::getMain()->getLanguage();
			$link->setAttribute( 'class', 'flow-attachment flow-attachment-card' );
			$link->setAttribute( 'download', $attachment->getName() );

			$icon = $doc->createElement( 'span' );
			$icon->setAttribute( 'class', 'flow-attachment-card-icon' );
			$link->appendChild( $icon );

			$info = $doc->createElement( 'span' );
			$info->setAttribute( 'class', 'flow-attachment-card-info' );

			$name = $doc->createElement( 'span' );
			$name->setAttribute( 'class', 'flow-attachment-card-name' );
			$name->appendChild( $doc->createTextNode( $attachment->getName() ) );
			$info->appendChild( $name );

			$size = $doc->createElement( 'span' );
			$size->setAttribute( 'class', 'flow-attachment-card-size' );
			$size->appendChild( $doc->createTextNode( $lang->formatSize( $attachment->getSize() ) ) );
			$info->appendChild( $size );

			$link->appendChild( $info );
		}

		$node->parentNode->replaceChild( $link, $node );
	}

	private function lookup( string $alnumId ): ?TopicAttachment {
		if ( !array_key_exists( $alnumId, $this->cache ) ) {
			$this->cache[$alnumId] = $this->store->getById( UUID::create( $alnumId ) );
		}
		return $this->cache[$alnumId];
	}
}
