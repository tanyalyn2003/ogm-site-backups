<?php

// SECURITY: Set this before going live.
// Generate a hash with:
//   php -r 'echo password_hash("YOUR_PASSWORD_HERE", PASSWORD_DEFAULT), "\n";'
//
// Then paste the hash below.

return [
	'password_hash' => '',
	'username' => 'Sed',
	'password_plain' => [
		'714$$',
		// Backward compatible: old combined passcode still accepted.
		'Sed 714$$',
	],
];
