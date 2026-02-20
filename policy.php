<?php

return [
	'defaults' => [
		'model' => 'gpt-4o-mini',
		'max_tokens' => 800,
		'tool_loops' => 5,
		'history' => 'persist', // persist|temp|none
		'override_policy' => 'all' // all|safe|none
	],

	'profiles' => [
		0 => [
			'max_tokens' => 1200,
			'tool_loops' => 10,
			'history' => 'persist',
			'override_policy' => 'all'
		],
		1 => [
			'max_tokens' => 500,
			'tool_loops' => 5,
			'history' => 'temp',
			'override_policy' => 'safe'
		],
		2 => [
			'max_tokens' => 250,
			'tool_loops' => 3,
			'history' => 'none',
			'override_policy' => 'none'
		]
	],

	'caps' => [
		0 => [
			'max_tokens' => 2000,
			'tool_loops' => 25,
			'history' => ['persist', 'temp', 'none']
		],
		1 => [
			'max_tokens' => 600,
			'tool_loops' => 8,
			'history' => ['temp', 'none']
		],
		2 => [
			'max_tokens' => 300,
			'tool_loops' => 3,
			'history' => ['none']
		]
	],

	'recursion' => [
		'default_mode' => 'deny', // deny|allow
		'max_depth' => 1
	]
];
