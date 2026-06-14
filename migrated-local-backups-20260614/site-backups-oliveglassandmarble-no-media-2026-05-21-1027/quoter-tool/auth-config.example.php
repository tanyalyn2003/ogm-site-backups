<?php

// Copy this file to auth-config.php on the server and fill in credentials.
// Do not commit auth-config.php with real secrets to public repos.
//
// Preferred (production): set password_hash only:
//   php -r 'echo password_hash("YOUR_PASSWORD", PASSWORD_DEFAULT), "\n";'
//
// Optional: set username (if empty, any username is accepted with the password).

return [
	'password_hash' => '',
	'username' => '',
	'password_plain' => '',
];
