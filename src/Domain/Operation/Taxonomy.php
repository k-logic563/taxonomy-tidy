<?php
/**
 * Supported operation taxonomies.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Domain\Operation;

/**
 * Prevents persistence of custom or mixed taxonomy operations.
 */
enum Taxonomy: string {
	case CATEGORY = 'category';
	case POST_TAG = 'post_tag';
}
