<?php

return [
  'session_name' => 'ogm_sales_admin',
  'timezone' => 'America/New_York',
  'report_email' => 'sed@oliveglassandmarble.com',
  'report_token' => 'lj9OOzWPXBq3zrrdjGb0driLKKWnCtPD',
  'users' => [
    'sales' => [
      'display_name' => 'Sales Team',
      'salt_hex' => 'acb2985dc2c48a7598953642bdfdf498',
      'iterations' => 120000,
      'hash_hex' => 'b2ea8554904b80013c5570cfc49aca46222f0c3c927a753866ed749298fbd403',
    ],
    'sed' => [
      'display_name' => 'Sed',
      'salt_hex' => '8a698078ffe0f5c75ab9ed0b5c6760a3',
      'iterations' => 120000,
      'hash_hex' => 'a7a29a28b2e081ef6a1849ad1caef88e6d014ee82eca7da0ff79d6b27f08f19a',
    ],
    'tanya' => [
      'display_name' => 'Tanya',
      'salt_hex' => '26a49074871c4a7a41608f3132ae2d93',
      'iterations' => 120000,
      'hash_hex' => '92ff8dc0c7cc3f2a4dd1a2ebdd4af7dacc5b0e3d61d720a3de970541e4d4c3c4',
    ],
    'brennon' => [
      'display_name' => 'Brennon',
      'salt_hex' => 'c4e214fb26acdb4324889f93ff5aea08',
      'iterations' => 120000,
      'hash_hex' => '249fceed9a9f0254489c30583140fe8aa40cacc41b81b4564eebd2a609d16d41',
    ],
  ],
  'protected_users' => [
    'sales',
  ],
  'owners' => [
    'Sed',
    'Brennon',
    'Tanya',
    'Allan',
  ],
];
