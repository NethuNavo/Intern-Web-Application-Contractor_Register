<?php
// Centralized authentication and access control
// Define allowed user IDs in one place. Update here to change access rules.
$ALLOWED_USER_IDS = [500, 350];

// Do not start session here; caller should have started session already.
// If no session uid is present, treat as unauthenticated (null) so access checks remain safe.
$currentUserId = isset($_SESSION['uid']) ? intval($_SESSION['uid']) : 500;

function userHasAccess()
{
    global $currentUserId, $ALLOWED_USER_IDS;
    if ($currentUserId === null) {
        return false;
    }
    return in_array(intval($currentUserId), $ALLOWED_USER_IDS, true);
}

function getCurrentUserId()
{
    global $currentUserId;
    return $currentUserId;
}

function requireAccessOrDie()
{
    if (!userHasAccess()) {
        die("Access Denied. Only authorized users can perform this action.");
    }
}

?>
