<?php

namespace Local\Index;

interface BlockInterface
{
    /** Stable id: the settings key, the DOM id, and the admin's handle for it. */
    public function id(): string;

    /** Translation key for the block heading, or null for an unheaded block. */
    public function titleKey(): ?string;

    public function icon(): ?string;

    /** Rails::SIDE_* */
    public function defaultSide(): string;

    public function defaultPosition(): int;

    /**
     * The block's markup, or NULL to be omitted entirely.
     *
     * Returning null rather than an empty card is deliberate and is the whole
     * contract: a rail of five cards, two of which say nothing, reads as a
     * broken page. A block that has an empty state worth showing returns that
     * state; a block that has nothing to say returns null and disappears.
     */
    public function render(Context $ctx): ?string;
}
