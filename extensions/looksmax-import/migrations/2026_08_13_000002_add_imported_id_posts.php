<?php

use Flarum\Database\Migration;

/**
 * Provenance for posts.
 *
 * Needed for quote attribution: a XenForo quote carries the SOURCE id of the
 * post it quotes (data-source="post: 27"), and the quoted post is very often
 * imported after the quoting one, so the link cannot be resolved at import
 * time. local/looksmax-format resolves it at render time through this column.
 *
 * Indexed because ResolveQuoteLinks looks up by it on every rendered quote and
 * 67% of posts contain at least one.
 */
return Migration::addColumns('posts', [
    'imported_id' => ['integer', 'unsigned' => true, 'nullable' => true],
]);
