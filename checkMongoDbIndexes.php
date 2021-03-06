<?php

require __DIR__ . '/vendor/autoload.php';

$uri = 'mongodb://localhost:27017';
$dbName = 'telegrambot';

$collections = [
    'user' => [
        'indexes' => [
            [
                'keys' => [
                    'id' => 1,
                ],
                'options' => [
                    'unique' => true
                ]
            ],
            [
                'keys' => [
                    'username' => 1,
                ],
                'options' => [

                ]
            ]
        ]
    ],
    'chat' => [
        'indexes' => [
            [
                'keys' => [
                    'id' => 1,
                ],
                'options' => [
                    'unique' => true
                ]
            ],
            [
                'keys' => [
                    'old_id' => 1,
                ],
                'options' => [
                ]
            ]
        ]
    ],
    'user_chat' => [
        'indexes' => [
            [
                'keys' => [
                    'user_id' => 1,
                    'chat_id' => 1,
                ],
                'options' => [
                    'unique' => true
                ]
            ]
        ]
    ],
    'inline_query' => [
        'indexes' => [
            [
                'keys' => [
                    'id' => 1,
                ],
                'options' => [
                    'unique' => true
                ]
            ],
            [
                'keys' => [
                    'user_id' => 1,
                ],
                'options' => [
                ]
            ],
        ]
    ],
    'chosen_inline_result' => [
        'indexes' => [
            [
                'keys' => [
                    'user_id' => 1,
                ],
                'options' => [
                ]
            ],
        ]
    ],
    'message' => [
        'indexes' => [
            [
                'keys' => [
                    'chat_id' => 1,
                    'id' => 1,
                ],
                'options' => [
                    'unique' => true
                ]
            ],
            [
                'keys' => [
                    'user_id' => 1,
                ],
                'options' => [
                ]
            ],
            [
                'keys' => [
                    'forward_from' => 1,
                ],
                'options' => [
                ]
            ],
            [
                'keys' => [
                    'forward_from_chat' => 1,
                ],
                'options' => [
                ]
            ],
            [
                'keys' => [
                    'reply_to_chat' => 1,
                ],
                'options' => [
                ]
            ],
            [
                'keys' => [
                    'reply_to_message' => 1,
                ],
                'options' => [
                ]
            ],
            [
                'keys' => [
                    'left_chat_member' => 1,
                ],
                'options' => [
                ]
            ],
            [
                'keys' => [
                    'migrate_from_chat_id' => 1,
                ],
                'options' => [
                ]
            ],
            [
                'keys' => [
                    'migrate_to_chat_id' => 1,
                ],
                'options' => [
                ]
            ],
        ]
    ],
    'callback_query' => [
        'indexes' => [
            [
                'keys' => [
                    'id' => 1,
                ],
                'options' => [
                    'unique' => true
                ]
            ],
            [
                'keys' => [
                    'user_id' => 1,
                ],
                'options' => [
                ]
            ],
            [
                'keys' => [
                    'chat_id' => 1,
                ],
                'options' => [
                ]
            ],
            [
                'keys' => [
                    'message_id' => 1,
                ],
                'options' => [
                ]
            ],
        ]
    ],
    'edited_message' => [
        'indexes' => [
            [
                'keys' => [
                    'user_id' => 1,
                ],
                'options' => [
                ]
            ],
            [
                'keys' => [
                    'chat_id' => 1,
                ],
                'options' => [
                ]
            ],
            [
                'keys' => [
                    'message_id' => 1,
                ],
                'options' => [
                ]
            ],
        ]
    ],
    'telegram_update' => [
        'indexes' => [
            [
                'keys' => [
                    'id' => 1,
                ],
                'options' => [
                    'unique' => true
                ]
            ],
            [
                'keys' => [
                    'message_id' => 1,
                    'chat_id' => 1,
                ],
                'options' => [
                ]
            ],
            [
                'keys' => [
                    'inline_query_id' => 1,
                ],
                'options' => [
                ]
            ],
            [
                'keys' => [
                    'chosen_inline_result_id' => 1,
                ],
                'options' => [
                ]
            ],
            [
                'keys' => [
                    'callback_query_id' => 1,
                ],
                'options' => [
                ]
            ],
            [
                'keys' => [
                    'edited_message_id' => 1,
                ],
                'options' => [
                ]
            ],
        ]
    ],
    'conversation' => [
        'indexes' => [
            [
                'keys' => [
                    'user_id' => 1
                ],
                'options' => [
                ]
            ],
            [
                'keys' => [
                    'chat_id' => 1,
                ],
                'options' => [
                ]
            ],
            [
                'keys' => [
                    'status' => 1,
                ],
                'options' => [
                ]
            ],
        ]
    ]
];
$client = new MongoDB\Client($uri, []);
$dataBase = $client->selectDatabase($dbName);

foreach ($collections as $collectionName => $indexes) {
    $listIndexes = $dataBase->selectCollection($collectionName)->listIndexes();

    foreach ($indexes['indexes'] as $index) {
        foreach ($listIndexes as $indexInfo) {
            if ($indexInfo->getKey() == $index['keys']) {
                error_log("index: {$indexInfo->getName()} already exists!");

                continue 2;
            }
        }
        $createdIndex = $dataBase->selectCollection($collectionName)->createIndex($index['keys'], $index['options']);

        error_log("Created index: {$createdIndex} on collection {$collectionName}");
    }
}



