<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

/**
 * Who may open a direct message.
 *
 * Members by default, which is what every forum these accounts came from does.
 * Guests are excluded by the permission not being granted to group 2 rather
 * than by a check in the controller, so an operator who wants a read-only
 * forum with DMs off can express it in the admin panel like any other
 * permission.
 *
 * Admins are omitted deliberately: Flarum's gate answers true for the
 * administrator group on every permission, and listing it here would put a
 * redundant row in `group_permission` that looks like a real grant.
 */
return Migration::addPermissions([
    'lmxdm.send' => [Group::MEMBER_ID, Group::MODERATOR_ID],
]);
