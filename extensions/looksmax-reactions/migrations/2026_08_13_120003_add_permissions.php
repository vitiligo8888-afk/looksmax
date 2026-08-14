<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

/**
 * Members can react and can see who reacted. Guests cannot react — anonymous
 * reactions were considered and rejected: fof/reactions implements them with a
 * hashed session id, a dedicated table and a middleware that smuggles the
 * request into a listener through the container, and the result is a count
 * nobody can audit and a trivially forgeable one.
 */
return Migration::addPermissions([
    'lmxreactions.react' => Group::MEMBER_ID,
    'lmxreactions.seeReactors' => Group::MEMBER_ID,
]);
