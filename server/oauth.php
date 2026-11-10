<?php
// Sign-in settings for the office server (/mcp): WorkOS AuthKit issues the tokens, homebox pushes the
// member and blocked lists. None of this is secret. Deployed with each release (deploy/deploy-office.sh).
defined('ABSPATH') || exit;
return [
    'issuer'             => 'https://progressive-canvas-22.authkit.app',
    'jwks_uri'           => 'https://progressive-canvas-22.authkit.app/oauth2/jwks',
    'resource'           => 'https://mcp.christianopenlibrary.org/mcp',
    'resource_metadata'  => 'https://mcp.christianopenlibrary.org/.well-known/oauth-protected-resource',
    'members_file'       => '/srv/library/links/members.json',
    'members_only'       => false,   // anyone signed in with GitHub (Matt, 2026-11-10); blocked accounts never
    'calls_per_day'      => 500,
    'calls_per_minute'   => 30,
    // Accounts that aren't library members share 80% of the server's global limits between them, so the
    // owner, personal links and members always keep the rest (RateLimit::pool).
    'open_share'         => 0.8,
    'request_access_url' => 'https://github.com/Christian-open-library',
];
