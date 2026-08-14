<?php

use Flarum\Extend;
use Local\Import\Console\ImportCommand;
use Local\Import\Console\TranslateCommand;

return [
    (new Extend\Console())
        ->command(ImportCommand::class)
        ->command(TranslateCommand::class),

    // The importer writes a handful of strings straight into post bodies and
    // discussion titles. They are content, not CLI output, so they need real
    // translations even though only a console command ever emits them.
    (new Extend\Locales(__DIR__.'/locale')),
];
