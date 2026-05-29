<?php
/**
 * Lightweight HTML-to-Markdown converter.
 *
 * Uses PHP's built-in DOMDocument — no Composer dependencies.
 *
 * @package AgentFriendlyWP
 */

namespace AgentFriendlyWP;

defined( 'ABSPATH' ) || exit;

class Html_To_Markdown {

	public function convert( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		$html = $this->strip_unwanted_elements( $html );

		$doc = new \DOMDocument();
		@$doc->loadHTML(
			'<?xml encoding="utf-8" ?><div>' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
		);

		$body = $doc->getElementsByTagName( 'div' )->item( 0 );
		if ( ! $body ) {
			return wp_strip_all_tags( $html );
		}

		$markdown = $this->process_node( $body );
		$markdown = preg_replace( '/\n{3,}/', "\n\n", $markdown );

		return trim( $markdown );
	}

	private function strip_unwanted_elements( string $html ): string {
		$tags = [ 'script', 'style', 'nav', 'footer', 'aside', 'noscript', 'iframe', 'form' ];
		foreach ( $tags as $tag ) {
			$html = preg_replace(
				'/<' . $tag . '\b[^>]*>.*?<\/' . $tag . '>/is',
				'',
				$html
			);
		}
		return $html;
	}

	private function process_node( \DOMNode $node ): string {
		if ( $node instanceof \DOMText ) {
			return $this->clean_text( $node->textContent );
		}

		if ( ! ( $node instanceof \DOMElement ) ) {
			return '';
		}

		$tag   = strtolower( $node->tagName );
		$inner = $this->process_children( $node );

		return $this->convert_element( $tag, $node, $inner );
	}

	private function process_children( \DOMNode $node ): string {
		$output = '';
		foreach ( $node->childNodes as $child ) {
			$output .= $this->process_node( $child );
		}
		return $output;
	}

	private function convert_element( string $tag, \DOMElement $el, string $inner ): string {
		$trimmed = trim( $inner );

		if ( '' === $trimmed && ! in_array( $tag, [ 'br', 'hr', 'img' ], true ) ) {
			return '';
		}

		switch ( $tag ) {
			case 'h1': return "\n\n# "      . $trimmed . "\n\n";
			case 'h2': return "\n\n## "     . $trimmed . "\n\n";
			case 'h3': return "\n\n### "    . $trimmed . "\n\n";
			case 'h4': return "\n\n#### "   . $trimmed . "\n\n";
			case 'h5': return "\n\n##### "  . $trimmed . "\n\n";
			case 'h6': return "\n\n###### " . $trimmed . "\n\n";

			case 'p':   return "\n\n" . $trimmed . "\n\n";
			case 'div': return "\n\n" . $trimmed . "\n\n";
			case 'br':  return "  \n";
			case 'hr':  return "\n\n---\n\n";

			case 'strong':
			case 'b':
				return '**' . $trimmed . '**';

			case 'em':
			case 'i':
				return '*' . $trimmed . '*';

			case 'del':
			case 's':
			case 'strike':
				return '~~' . $trimmed . '~~';

			case 'code':
				return '`' . $trimmed . '`';

			case 'a':
				$href  = $el->getAttribute( 'href' );
				$title = $el->getAttribute( 'title' );
				if ( '' === $href ) {
					return $trimmed;
				}
				if ( $title ) {
					return '[' . $trimmed . '](' . $href . ' "' . $title . '")';
				}
				return '[' . $trimmed . '](' . $href . ')';

			case 'img':
				$src = $el->getAttribute( 'data-src' )
					?: $el->getAttribute( 'data-lazy-src' )
					?: $el->getAttribute( 'data-original' )
					?: $el->getAttribute( 'src' );
				if ( '' === $src || str_starts_with( $src, 'data:' ) ) {
					return '';
				}
				$alt = $el->getAttribute( 'alt' );
				return '![' . $alt . '](' . $src . ')';

			case 'blockquote':
				$lines  = explode( "\n", trim( $inner ) );
				$quoted = array_map( function ( $line ) {
					return '> ' . $line;
				}, $lines );
				return "\n\n" . implode( "\n", $quoted ) . "\n\n";

			case 'pre':
				$code_el      = $el->getElementsByTagName( 'code' )->item( 0 );
				$code_content = $code_el ? $code_el->textContent : $el->textContent;
				$lang         = '';
				if ( $code_el ) {
					$class = $code_el->getAttribute( 'class' );
					if ( preg_match( '/(?:language|lang)-(\w+)/', $class, $m ) ) {
						$lang = $m[1];
					}
				}
				return "\n\n```" . $lang . "\n" . trim( $code_content ) . "\n```\n\n";

			case 'ul': return "\n\n" . $this->convert_list( $el, false ) . "\n\n";
			case 'ol': return "\n\n" . $this->convert_list( $el, true )  . "\n\n";
			case 'li': return $trimmed . "\n";

			case 'table': return "\n\n" . $this->convert_table( $el ) . "\n\n";

			case 'figure':     return "\n\n" . $trimmed . "\n\n";
			case 'figcaption': return "\n*" . $trimmed . "*\n";

			case 'span':
			case 'small':
			case 'mark':
			case 'abbr':
			case 'cite':
			case 'q':
			case 'sup':
			case 'sub':
				return $inner;

			default:
				return $inner;
		}
	}

	private function convert_list( \DOMElement $list, bool $ordered, int $depth = 0 ): string {
		$lines  = [];
		$index  = 1;
		$indent = str_repeat( '  ', $depth );

		foreach ( $list->childNodes as $child ) {
			if ( ! ( $child instanceof \DOMElement ) || 'li' !== strtolower( $child->tagName ) ) {
				continue;
			}

			$item_parts = [];
			$sub_list   = null;

			foreach ( $child->childNodes as $li_child ) {
				if ( $li_child instanceof \DOMElement
					&& in_array( strtolower( $li_child->tagName ), [ 'ul', 'ol' ], true ) ) {
					$sub_list = $li_child;
				} else {
					$item_parts[] = $this->process_node( $li_child );
				}
			}

			$item_text = trim( implode( '', $item_parts ) );
			$bullet    = $ordered ? $index . '. ' : '- ';
			$lines[]   = $indent . $bullet . $item_text;

			if ( $sub_list ) {
				$is_ol   = 'ol' === strtolower( $sub_list->tagName );
				$lines[] = $this->convert_list( $sub_list, $is_ol, $depth + 1 );
			}

			$index++;
		}

		return implode( "\n", $lines );
	}

	private function convert_table( \DOMElement $table ): string {
		$rows       = [];
		$has_header = false;

		foreach ( $table->childNodes as $section ) {
			if ( ! ( $section instanceof \DOMElement ) ) {
				continue;
			}
			$section_tag = strtolower( $section->tagName );

			if ( 'tr' === $section_tag ) {
				$rows[] = [ 'row' => $section, 'is_header' => false ];
			} elseif ( in_array( $section_tag, [ 'thead', 'tbody', 'tfoot' ], true ) ) {
				foreach ( $section->childNodes as $tr ) {
					if ( $tr instanceof \DOMElement && 'tr' === strtolower( $tr->tagName ) ) {
						$is_header = ( 'thead' === $section_tag );
						if ( $is_header ) {
							$has_header = true;
						}
						$rows[] = [ 'row' => $tr, 'is_header' => $is_header ];
					}
				}
			}
		}

		if ( empty( $rows ) ) {
			return '';
		}

		$lines = [];

		foreach ( $rows as $i => $entry ) {
			$cells = [];
			foreach ( $entry['row']->childNodes as $cell ) {
				if ( $cell instanceof \DOMElement
					&& in_array( strtolower( $cell->tagName ), [ 'td', 'th' ], true ) ) {
					$cells[] = trim( $this->process_children( $cell ) );
				}
			}
			$lines[] = '| ' . implode( ' | ', $cells ) . ' |';

			if ( $entry['is_header'] || ( 0 === $i && ! $has_header ) ) {
				$sep     = array_fill( 0, count( $cells ), '---' );
				$lines[] = '| ' . implode( ' | ', $sep ) . ' |';
			}
		}

		return implode( "\n", $lines );
	}

	private function clean_text( string $text ): string {
		return preg_replace( '/[^\S\n]+/', ' ', $text );
	}
}
