<?php
/**
 * This file is part of the TelegramBot package.
 *
 * (c) Avtandil Kikabidze aka LONGMAN <akalongman@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 * Written by Marco Boretto <marco.bore@gmail.com>
 */

namespace Longman\TelegramBot;

use Exception;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\Chat;
use Longman\TelegramBot\Entities\ChosenInlineResult;
use Longman\TelegramBot\Entities\InlineQuery;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\ReplyToMessage;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Entities\User;
use Longman\TelegramBot\Exception\TelegramException;
use PDO;
use PDOException;

abstract class DBBase
{
    /**
     * MySQL credentials
     *
     * @var array
     */
    protected $mysql_credentials = [];

    /**
     * PDO object
     *
     * @var PDO
     */
    protected $pdo;

    /**
     * Table prefix
     *
     * @var string
     */
    protected $table_prefix;

    /**
     * Telegram class object
     *
     * @var Telegram
     */
    protected $telegram;

    /**
     * @var self
     */
    protected static $instance;


    /**
     * Initialize
     *
     * @param array    $credentials  Database connection details
     * @param Telegram $telegram     Telegram object to connect with this object
     * @param string   $table_prefix Table prefix
     * @param string   $encoding     Database character encoding
     *
     * @return DB
     * @throws TelegramException
     */
    public function initialize(
        array $credentials,
        Telegram $telegram,
        $table_prefix = null,
        $encoding = 'utf8mb4'
    ) {
        if (self::$instance !== null) {
            return self::$instance;
        }
        if (empty($credentials)) {
            throw new TelegramException('MySQL credentials not provided!');
        }

        $this->initDb($credentials, $encoding);

        $this->telegram          = $telegram;
        $this->mysql_credentials = $credentials;
        $this->table_prefix      = $table_prefix;
        self::$instance          = $this;

        $this->defineTables();

        return $this;
    }

    abstract protected function initDb(array $credentials, $encoding = 'utf8mb4');

    /**
     * @return DB|null
     */
    public static function getInstance()
    {
        return self::$instance;
    }

    /**
     * Define all the tables with the proper prefix
     */
    protected function defineTables()
    {
        $tables = [
            'callback_query',
            'chat',
            'chosen_inline_result',
            'edited_message',
            'inline_query',
            'message',
            'request_limiter',
            'telegram_update',
            'user',
            'user_chat',
            'conversation',
            'botan_shortener'
        ];
        foreach ($tables as $table) {
            $table_name = 'TB_' . strtoupper($table);
            if (!defined($table_name)) {
                define($table_name, $this->table_prefix . $table);
            }
        }
    }

    /**
     * Check if database connection has been created
     *
     * @return bool
     */
    abstract public function isDbConnected();

    /**
     * Fetch update(s) from DB
     *
     * @param int    $limit Limit the number of updates to fetch
     * @param string $id    Check for unique update id
     *
     * @return array|bool Fetched data or false if not connected
     * @throws TelegramException
     */
    abstract public function selectTelegramUpdate($limit = null, $id = null);

    /**
     * Fetch message(s) from DB
     *
     * @param int $limit Limit the number of messages to fetch
     *
     * @return array|bool Fetched data or false if not connected
     * @throws TelegramException
     */
    abstract public function selectMessages($limit = null);

    /**
     * Convert from unix timestamp to timestamp
     *
     * @param int $time Unix timestamp (if empty, current timestamp is used)
     *
     * @return string
     */
    protected function getTimestamp($time = null)
    {
        return date('Y-m-d H:i:s', $time ?: time());
    }

    /**
     * Convert array of Entity items to a JSON array
     *
     * @todo Find a better way, as json_* functions are very heavy
     *
     * @param array|null $entities
     * @param mixed      $default
     *
     * @return mixed
     */
    public function entitiesArrayToJson($entities, $default = null)
    {
        if (!is_array($entities)) {
            return $default;
        }

        // Convert each Entity item into an object based on its JSON reflection
        $json_entities = array_map(function ($entity) {
            return json_decode($entity, true);
        }, $entities);

        return json_encode($json_entities);
    }

    /**
     * Insert entry to telegram_update table
     *
     * @todo Add missing values! See https://core.telegram.org/bots/api#update
     *
     * @param string $id
     * @param string $chat_id
     * @param string $message_id
     * @param string $inline_query_id
     * @param string $chosen_inline_result_id
     * @param string $callback_query_id
     * @param string $edited_message_id
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public function insertTelegramUpdate(
        $id,
        $chat_id = null,
        $message_id = null,
        $inline_query_id = null,
        $chosen_inline_result_id = null,
        $callback_query_id = null,
        $edited_message_id = null
    ) {
        if ($message_id === null && $inline_query_id === null && $chosen_inline_result_id === null && $callback_query_id === null && $edited_message_id === null) {
            throw new TelegramException('message_id, inline_query_id, chosen_inline_result_id, callback_query_id, edited_message_id are all null');
        }

        if (!$this->isDbConnected()) {
            return false;
        }

        return $this->insertTelegramUpdateToDb($id, $chat_id, $message_id, $inline_query_id, $chosen_inline_result_id, $callback_query_id, $edited_message_id);
    }

    abstract protected function insertTelegramUpdateToDb($id, $chat_id = null, $message_id = null, $inline_query_id = null, $chosen_inline_result_id = null, $callback_query_id = null, $edited_message_id = null);

    /**
     * Insert users and save their connection to chats
     *
     * @param User   $user
     * @param string $date
     * @param Chat   $chat
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public function insertUser(User $user, $date = null, Chat $chat = null)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        $status = $this->insertUserToDb($user);

        // Also insert the relationship to the chat into the user_chat table
        if ($chat instanceof Chat) {
            $status = $this->insertUserChatRelation($user, $chat);
        }

        return $status;
    }

    /**
     * @param User $user
     *
     * @return bool
     */
    abstract protected function insertUserToDb(User $user);

    /**
     * @param User $user
     * @param Chat $chat
     *
     * @return bool
     */
    abstract protected function insertUserChatRelation(User $user, Chat $chat);

    /**
     * Insert chat
     *
     * @param Chat   $chat
     * @param string $date
     * @param string $migrate_to_chat_id
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public function insertChat(Chat $chat, $date = null, $migrate_to_chat_id = null)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        $type = $chat->getType();
        $id = $chat->getId();
        $oldId = $migrate_to_chat_id;

        if ($migrate_to_chat_id !== null) {
            $type = 'supergroup';
            $id = $migrate_to_chat_id;
            $oldId = $chat->getId();
        }
        $createdAt = $date;
        $updatedAt = $date;

        $this->insertChatToDb($chat, $id, $oldId, $type, $createdAt, $updatedAt);
    }

    /**
     * @param $chat
     * @param $id
     * @param $oldId
     * @param $type
     * @param $createdAt
     * @param $updatedAt
     *
     * @return bool
     */
    abstract protected function insertChatToDb($chat, $id, $oldId, $type, $createdAt, $updatedAt);

    /**
     * Insert request into database
     *
     * @todo $this->pdo->lastInsertId() - unsafe usage if expected previous insert fails?
     *
     * @param Update $update
     *
     * @return bool
     * @throws TelegramException
     */
    public function insertRequest(Update $update)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        $update_id   = $update->getUpdateId();
        $update_type = $update->getUpdateType();

        // @todo Make this simpler: if ($message = $update->getMessage()) ...
        if ($update_type === 'message') {
            $message = $update->getMessage();

            if ($this->insertMessageRequest($message)) {
                $message_id = $message->getMessageId();
                $chat_id    = $message->getChat()->getId();

                return $this->insertTelegramUpdate(
                    $update_id,
                    $chat_id,
                    $message_id
                );
            }
        } elseif ($update_type === 'edited_message') {
            $edited_message = $update->getEditedMessage();

            if ($this->insertEditedMessageRequest($edited_message)) {
                $edited_message_local_id = $this->pdo->lastInsertId();
                $chat_id                 = $edited_message->getChat()->getId();

                return $this->insertTelegramUpdate(
                    $update_id,
                    $chat_id,
                    null,
                    null,
                    null,
                    null,
                    $edited_message_local_id
                );
            }
        } elseif ($update_type === 'channel_post') {
            $channel_post = $update->getChannelPost();

            if ($this->insertMessageRequest($channel_post)) {
                $message_id = $channel_post->getMessageId();
                $chat_id    = $channel_post->getChat()->getId();

                return $this->insertTelegramUpdate(
                    $update_id,
                    $chat_id,
                    $message_id
                );
            }
        } elseif ($update_type === 'edited_channel_post') {
            $edited_channel_post = $update->getEditedChannelPost();

            if ($this->insertEditedMessageRequest($edited_channel_post)) {
                $edited_channel_post_local_id = $this->pdo->lastInsertId();
                $chat_id                      = $edited_channel_post->getChat()->getId();

                return $this->insertTelegramUpdate(
                    $update_id,
                    $chat_id,
                    null,
                    null,
                    null,
                    null,
                    $edited_channel_post_local_id
                );
            }
        } elseif ($update_type === 'inline_query') {
            $inline_query = $update->getInlineQuery();

            if ($this->insertInlineQueryRequest($inline_query)) {
                $inline_query_id = $inline_query->getId();

                return $this->insertTelegramUpdate(
                    $update_id,
                    null,
                    null,
                    $inline_query_id
                );
            }
        } elseif ($update_type === 'chosen_inline_result') {
            $chosen_inline_result = $update->getChosenInlineResult();

            if ($this->insertChosenInlineResultRequest($chosen_inline_result)) {
                $chosen_inline_result_local_id = $this->pdo->lastInsertId();

                return $this->insertTelegramUpdate(
                    $update_id,
                    null,
                    null,
                    null,
                    $chosen_inline_result_local_id
                );
            }
        } elseif ($update_type === 'callback_query') {
            $callback_query = $update->getCallbackQuery();

            if ($this->insertCallbackQueryRequest($callback_query)) {
                $callback_query_id = $callback_query->getId();

                return $this->insertTelegramUpdate(
                    $update_id,
                    null,
                    null,
                    null,
                    null,
                    $callback_query_id
                );
            }
        }

        return false;
    }

    /**
     * Insert inline query request into database
     *
     * @param InlineQuery $inline_query
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    abstract public function insertInlineQueryRequest(InlineQuery $inline_query);

    /**
     * Insert chosen inline result request into database
     *
     * @param ChosenInlineResult $chosen_inline_result
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public function insertChosenInlineResultRequest(ChosenInlineResult $chosen_inline_result)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        $date    = $this->getTimestamp();
        $user_id = null;

        $user = $chosen_inline_result->getFrom();
        if ($user instanceof User) {
            $user_id = $user->getId();
            $this->insertUser($user, $date);
        }

        $created_at = $date;

        return $this->insertChosenInlineResultRequestToDb($chosen_inline_result, $user_id, $created_at);
    }

    /**
     * @param ChosenInlineResult $chosen_inline_result
     * @param                    $user_id
     * @param                    $created_at
     *
     * @return bool
     */
    abstract protected function insertChosenInlineResultRequestToDb(ChosenInlineResult $chosen_inline_result, $user_id, $created_at);

    /**
     * Insert callback query request into database
     *
     * @param CallbackQuery $callback_query
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public function insertCallbackQueryRequest(CallbackQuery $callback_query)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        try {
            $sth = $this->pdo->prepare('
                INSERT IGNORE INTO `' . TB_CALLBACK_QUERY . '`
                (`id`, `user_id`, `chat_id`, `message_id`, `inline_message_id`, `data`, `created_at`)
                VALUES
                (:id, :user_id, :chat_id, :message_id, :inline_message_id, :data, :created_at)
            ');

            $date    = $this->getTimestamp();
            $user_id = null;

            $user = $callback_query->getFrom();
            if ($user instanceof User) {
                $user_id = $user->getId();
                $this->insertUser($user, $date);
            }

            $message    = $callback_query->getMessage();
            $chat_id    = null;
            $message_id = null;
            if ($message instanceof Message) {
                $chat_id    = $message->getChat()->getId();
                $message_id = $message->getMessageId();

                $is_message = $this->pdo->query('
                    SELECT *
                    FROM `' . TB_MESSAGE . '`
                    WHERE `id` = ' . $message_id . '
                      AND `chat_id` = ' . $chat_id . '
                    LIMIT 1
                ')->rowCount();

                if ($is_message) {
                    $this->insertEditedMessageRequest($message);
                } else {
                    $this->insertMessageRequest($message);
                }
            }

            $sth->bindValue(':id', $callback_query->getId());
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':chat_id', $chat_id);
            $sth->bindValue(':message_id', $message_id);
            $sth->bindValue(':inline_message_id', $callback_query->getInlineMessageId());
            $sth->bindValue(':data', $callback_query->getData());
            $sth->bindValue(':created_at', $date);

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert Message request in db
     *
     * @todo Complete with new fields: https://core.telegram.org/bots/api#message
     *
     * @param Message $message
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public function insertMessageRequest(Message $message)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        $date = $this->getTimestamp($message->getDate());

        // Insert chat, update chat id in case it migrated
        $chat = $message->getChat();
        $this->insertChat($chat, $date, $message->getMigrateToChatId());

        // Insert user and the relation with the chat
        $user = $message->getFrom();
        if ($user instanceof User) {
            $this->insertUser($user, $date, $chat);
        }

        // Insert the forwarded message user in users table
        $forward_date = null;
        $forward_from = $message->getForwardFrom();
        if ($forward_from instanceof User) {
            $this->insertUser($forward_from, $forward_date);
            $forward_from = $forward_from->getId();
            $forward_date = $this->getTimestamp($message->getForwardDate());
        }
        $forward_from_chat = $message->getForwardFromChat();
        if ($forward_from_chat instanceof Chat) {
            $this->insertChat($forward_from_chat, $forward_date);
            $forward_from_chat = $forward_from_chat->getId();
            $forward_date      = $this->getTimestamp($message->getForwardDate());
        }

        // New and left chat member
        $new_chat_members_ids = null;
        $left_chat_member_id  = null;

        $new_chat_members = $message->getNewChatMembers();
        $left_chat_member = $message->getLeftChatMember();
        if (!empty($new_chat_members)) {
            foreach ($new_chat_members as $new_chat_member) {
                if ($new_chat_member instanceof User) {
                    // Insert the new chat user
                    $this->insertUser($new_chat_member, $date, $chat);
                    $new_chat_members_ids[] = $new_chat_member->getId();
                }
            }
            $new_chat_members_ids = implode(',', $new_chat_members_ids);
        } elseif ($left_chat_member instanceof User) {
            // Insert the left chat user
            $this->insertUser($left_chat_member, $date, $chat);
            $left_chat_member_id = $left_chat_member->getId();
        }

        try {
            $sth = $this->pdo->prepare('
                INSERT IGNORE INTO `' . TB_MESSAGE . '`
                (
                    `id`, `user_id`, `chat_id`, `date`, `forward_from`, `forward_from_chat`, `forward_from_message_id`,
                    `forward_date`, `reply_to_chat`, `reply_to_message`, `media_group_id`, `text`, `entities`, `audio`, `document`,
                    `animation`, `game`, `photo`, `sticker`, `video`, `voice`, `video_note`, `caption`, `contact`,
                    `location`, `venue`, `new_chat_members`, `left_chat_member`,
                    `new_chat_title`,`new_chat_photo`, `delete_chat_photo`, `group_chat_created`,
                    `supergroup_chat_created`, `channel_chat_created`,
                    `migrate_from_chat_id`, `migrate_to_chat_id`, `pinned_message`, `connected_website`, `passport_data`
                ) VALUES (
                    :message_id, :user_id, :chat_id, :date, :forward_from, :forward_from_chat, :forward_from_message_id,
                    :forward_date, :reply_to_chat, :reply_to_message, :media_group_id, :text, :entities, :audio, :document,
                    :animation, :game, :photo, :sticker, :video, :voice, :video_note, :caption, :contact,
                    :location, :venue, :new_chat_members, :left_chat_member,
                    :new_chat_title, :new_chat_photo, :delete_chat_photo, :group_chat_created,
                    :supergroup_chat_created, :channel_chat_created,
                    :migrate_from_chat_id, :migrate_to_chat_id, :pinned_message, :connected_website, :passport_data
                )
            ');

            $user_id = null;
            if ($user instanceof User) {
                $user_id = $user->getId();
            }
            $chat_id = $chat->getId();

            $reply_to_message    = $message->getReplyToMessage();
            $reply_to_message_id = null;
            if ($reply_to_message instanceof ReplyToMessage) {
                $reply_to_message_id = $reply_to_message->getMessageId();
                // please notice that, as explained in the documentation, reply_to_message don't contain other
                // reply_to_message field so recursion deep is 1
                $this->insertMessageRequest($reply_to_message);
            }

            $sth->bindValue(':message_id', $message->getMessageId());
            $sth->bindValue(':chat_id', $chat_id);
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':date', $date);
            $sth->bindValue(':forward_from', $forward_from);
            $sth->bindValue(':forward_from_chat', $forward_from_chat);
            $sth->bindValue(':forward_from_message_id', $message->getForwardFromMessageId());
            $sth->bindValue(':forward_date', $forward_date);

            $reply_to_chat_id = null;
            if ($reply_to_message_id !== null) {
                $reply_to_chat_id = $chat_id;
            }
            $sth->bindValue(':reply_to_chat', $reply_to_chat_id);
            $sth->bindValue(':reply_to_message', $reply_to_message_id);

            $sth->bindValue(':media_group_id', $message->getMediaGroupId());
            $sth->bindValue(':text', $message->getText());
            $sth->bindValue(':entities', $t = $this->entitiesArrayToJson($message->getEntities(), null));
            $sth->bindValue(':audio', $message->getAudio());
            $sth->bindValue(':document', $message->getDocument());
            $sth->bindValue(':animation', $message->getAnimation());
            $sth->bindValue(':game', $message->getGame());
            $sth->bindValue(':photo', $t = $this->entitiesArrayToJson($message->getPhoto(), null));
            $sth->bindValue(':sticker', $message->getSticker());
            $sth->bindValue(':video', $message->getVideo());
            $sth->bindValue(':voice', $message->getVoice());
            $sth->bindValue(':video_note', $message->getVideoNote());
            $sth->bindValue(':caption', $message->getCaption());
            $sth->bindValue(':contact', $message->getContact());
            $sth->bindValue(':location', $message->getLocation());
            $sth->bindValue(':venue', $message->getVenue());
            $sth->bindValue(':new_chat_members', $new_chat_members_ids);
            $sth->bindValue(':left_chat_member', $left_chat_member_id);
            $sth->bindValue(':new_chat_title', $message->getNewChatTitle());
            $sth->bindValue(':new_chat_photo', $t = $this->entitiesArrayToJson($message->getNewChatPhoto(), null));
            $sth->bindValue(':delete_chat_photo', $message->getDeleteChatPhoto());
            $sth->bindValue(':group_chat_created', $message->getGroupChatCreated());
            $sth->bindValue(':supergroup_chat_created', $message->getSupergroupChatCreated());
            $sth->bindValue(':channel_chat_created', $message->getChannelChatCreated());
            $sth->bindValue(':migrate_from_chat_id', $message->getMigrateFromChatId());
            $sth->bindValue(':migrate_to_chat_id', $message->getMigrateToChatId());
            $sth->bindValue(':pinned_message', $message->getPinnedMessage());
            $sth->bindValue(':connected_website', $message->getConnectedWebsite());
            $sth->bindValue(':passport_data', $message->getPassportData());

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert Edited Message request in db
     *
     * @param Message $edited_message
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public function insertEditedMessageRequest(Message $edited_message)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        try {
            $edit_date = $this->getTimestamp($edited_message->getEditDate());

            // Insert chat
            $chat = $edited_message->getChat();
            $this->insertChat($chat, $edit_date);

            // Insert user and the relation with the chat
            $user = $edited_message->getFrom();
            if ($user instanceof User) {
                $this->insertUser($user, $edit_date, $chat);
            }

            $sth = $this->pdo->prepare('
                INSERT IGNORE INTO `' . TB_EDITED_MESSAGE . '`
                (`chat_id`, `message_id`, `user_id`, `edit_date`, `text`, `entities`, `caption`)
                VALUES
                (:chat_id, :message_id, :user_id, :edit_date, :text, :entities, :caption)
            ');

            $user_id = null;
            if ($user instanceof User) {
                $user_id = $user->getId();
            }

            $sth->bindValue(':chat_id', $chat->getId());
            $sth->bindValue(':message_id', $edited_message->getMessageId());
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':edit_date', $edit_date);
            $sth->bindValue(':text', $edited_message->getText());
            $sth->bindValue(':entities', $this->entitiesArrayToJson($edited_message->getEntities(), null));
            $sth->bindValue(':caption', $edited_message->getCaption());

            return $sth->execute();
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Select Groups, Supergroups, Channels and/or single user Chats (also by ID or text)
     *
     * @param $select_chats_params
     *
     * @return array|bool
     * @throws TelegramException
     */
    public function selectChats($select_chats_params)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        // Set defaults for omitted values.
        $select = array_merge([
            'groups'      => true,
            'supergroups' => true,
            'channels'    => true,
            'users'       => true,
            'date_from'   => null,
            'date_to'     => null,
            'chat_id'     => null,
            'text'        => null,
        ], $select_chats_params);

        if (!$select['groups'] && !$select['users'] && !$select['supergroups'] && !$select['channels']) {
            return false;
        }

        try {
            $query = '
                SELECT * ,
                ' . TB_CHAT . '.`id` AS `chat_id`,
                ' . TB_CHAT . '.`username` AS `chat_username`,
                ' . TB_CHAT . '.`created_at` AS `chat_created_at`,
                ' . TB_CHAT . '.`updated_at` AS `chat_updated_at`
            ';
            if ($select['users']) {
                $query .= '
                    , ' . TB_USER . '.`id` AS `user_id`
                    FROM `' . TB_CHAT . '`
                    LEFT JOIN `' . TB_USER . '`
                    ON ' . TB_CHAT . '.`id`=' . TB_USER . '.`id`
                ';
            } else {
                $query .= 'FROM `' . TB_CHAT . '`';
            }

            // Building parts of query
            $where  = [];
            $tokens = [];

            if (!$select['groups'] || !$select['users'] || !$select['supergroups'] || !$select['channels']) {
                $chat_or_user = [];

                $select['groups'] && $chat_or_user[] = TB_CHAT . '.`type` = "group"';
                $select['supergroups'] && $chat_or_user[] = TB_CHAT . '.`type` = "supergroup"';
                $select['channels'] && $chat_or_user[] = TB_CHAT . '.`type` = "channel"';
                $select['users'] && $chat_or_user[] = TB_CHAT . '.`type` = "private"';

                $where[] = '(' . implode(' OR ', $chat_or_user) . ')';
            }

            if (null !== $select['date_from']) {
                $where[]              = TB_CHAT . '.`updated_at` >= :date_from';
                $tokens[':date_from'] = $select['date_from'];
            }

            if (null !== $select['date_to']) {
                $where[]            = TB_CHAT . '.`updated_at` <= :date_to';
                $tokens[':date_to'] = $select['date_to'];
            }

            if (null !== $select['chat_id']) {
                $where[]            = TB_CHAT . '.`id` = :chat_id';
                $tokens[':chat_id'] = $select['chat_id'];
            }

            if (null !== $select['text']) {
                $text_like = '%' . strtolower($select['text']) . '%';
                if ($select['users']) {
                    $where[]          = '(
                        LOWER(' . TB_CHAT . '.`title`) LIKE :text1
                        OR LOWER(' . TB_USER . '.`first_name`) LIKE :text2
                        OR LOWER(' . TB_USER . '.`last_name`) LIKE :text3
                        OR LOWER(' . TB_USER . '.`username`) LIKE :text4
                    )';
                    $tokens[':text1'] = $text_like;
                    $tokens[':text2'] = $text_like;
                    $tokens[':text3'] = $text_like;
                    $tokens[':text4'] = $text_like;
                } else {
                    $where[]         = 'LOWER(' . TB_CHAT . '.`title`) LIKE :text';
                    $tokens[':text'] = $text_like;
                }
            }

            if (!empty($where)) {
                $query .= ' WHERE ' . implode(' AND ', $where);
            }

            $query .= ' ORDER BY ' . TB_CHAT . '.`updated_at` ASC';

            $sth = $this->pdo->prepare($query);
            $sth->execute($tokens);

            return $sth->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Get Telegram API request count for current chat / message
     *
     * @param integer $chat_id
     * @param string  $inline_message_id
     *
     * @return array|bool Array containing TOTAL and CURRENT fields or false on invalid arguments
     * @throws TelegramException
     */
    public function getTelegramRequestCount($chat_id = null, $inline_message_id = null)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        try {
            $sth = $this->pdo->prepare('SELECT 
                (SELECT COUNT(DISTINCT `chat_id`) FROM `' . TB_REQUEST_LIMITER . '` WHERE `created_at` >= :created_at_1) AS LIMIT_PER_SEC_ALL,
                (SELECT COUNT(*) FROM `' . TB_REQUEST_LIMITER . '` WHERE `created_at` >= :created_at_2 AND ((`chat_id` = :chat_id_1 AND `inline_message_id` IS NULL) OR (`inline_message_id` = :inline_message_id AND `chat_id` IS NULL))) AS LIMIT_PER_SEC,
                (SELECT COUNT(*) FROM `' . TB_REQUEST_LIMITER . '` WHERE `created_at` >= :created_at_minute AND `chat_id` = :chat_id_2) AS LIMIT_PER_MINUTE
            ');

            $date        = $this->getTimestamp();
            $date_minute = $this->getTimestamp(strtotime('-1 minute'));

            $sth->bindValue(':chat_id_1', $chat_id);
            $sth->bindValue(':chat_id_2', $chat_id);
            $sth->bindValue(':inline_message_id', $inline_message_id);
            $sth->bindValue(':created_at_1', $date);
            $sth->bindValue(':created_at_2', $date);
            $sth->bindValue(':created_at_minute', $date_minute);

            $sth->execute();

            return $sth->fetch();
        } catch (Exception $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert Telegram API request in db
     *
     * @param string $method
     * @param array  $data
     *
     * @return bool If the insert was successful
     * @throws TelegramException
     */
    public function insertTelegramRequest($method, $data)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        try {
            $sth = $this->pdo->prepare('INSERT INTO `' . TB_REQUEST_LIMITER . '`
                (`method`, `chat_id`, `inline_message_id`, `created_at`)
                VALUES
                (:method, :chat_id, :inline_message_id, :created_at);
            ');

            $chat_id           = isset($data['chat_id']) ? $data['chat_id'] : null;
            $inline_message_id = isset($data['inline_message_id']) ? $data['inline_message_id'] : null;

            $sth->bindValue(':chat_id', $chat_id);
            $sth->bindValue(':inline_message_id', $inline_message_id);
            $sth->bindValue(':method', $method);
            $sth->bindValue(':created_at', $this->getTimestamp());

            return $sth->execute();
        } catch (Exception $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Bulk update the entries of any table
     *
     * @param string $table
     * @param array  $fields_values
     * @param array  $where_fields_values
     *
     * @return bool
     * @throws TelegramException
     */
    public function update($table, array $fields_values, array $where_fields_values)
    {
        if (empty($fields_values) || !$this->isDbConnected()) {
            return false;
        }

        try {
            // Building parts of query
            $tokens = $fields = $where = [];

            // Fields with values to update
            foreach ($fields_values as $field => $value) {
                $token          = ':' . count($tokens);
                $fields[]       = "`{$field}` = {$token}";
                $tokens[$token] = $value;
            }

            // Where conditions
            foreach ($where_fields_values as $field => $value) {
                $token          = ':' . count($tokens);
                $where[]        = "`{$field}` = {$token}";
                $tokens[$token] = $value;
            }

            $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $fields);
            $sql .= count($where) > 0 ? ' WHERE ' . implode(' AND ', $where) : '';

            return $this->pdo->prepare($sql)->execute($tokens);
        } catch (Exception $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Select a conversation from the DB
     *
     * @param string   $user_id
     * @param string   $chat_id
     * @param int|null $limit
     *
     * @return array|bool
     * @throws TelegramException
     */
    public function selectConversation($user_id, $chat_id, $limit = null)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        try {
            $sql = '
              SELECT *
              FROM `' . TB_CONVERSATION . '`
              WHERE `status` = :status
                AND `chat_id` = :chat_id
                AND `user_id` = :user_id
            ';

            if ($limit !== null) {
                $sql .= ' LIMIT :limit';
            }

            $sth = $this->pdo->prepare($sql);

            $sth->bindValue(':status', 'active');
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':chat_id', $chat_id);

            if ($limit !== null) {
                $sth->bindValue(':limit', $limit, PDO::PARAM_INT);
            }

            $sth->execute();

            return $sth->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert the conversation in the database
     *
     * @param string $user_id
     * @param string $chat_id
     * @param string $command
     *
     * @return bool
     * @throws TelegramException
     */
    public function insertConversation($user_id, $chat_id, $command)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        try {
            $sth = $this->pdo->prepare('INSERT INTO `' . TB_CONVERSATION . '`
                (`status`, `user_id`, `chat_id`, `command`, `notes`, `created_at`, `updated_at`)
                VALUES
                (:status, :user_id, :chat_id, :command, :notes, :created_at, :updated_at)
            ');

            $date = $this->getTimestamp();

            $sth->bindValue(':status', 'active');
            $sth->bindValue(':command', $command);
            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':chat_id', $chat_id);
            $sth->bindValue(':notes', '[]');
            $sth->bindValue(':created_at', $date);
            $sth->bindValue(':updated_at', $date);

            return $sth->execute();
        } catch (Exception $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Update a specific conversation
     *
     * @param array $fields_values
     * @param array $where_fields_values
     *
     * @return bool
     * @throws TelegramException
     */
    public function updateConversation(array $fields_values, array $where_fields_values)
    {
        // Auto update the update_at field.
        $fields_values['updated_at'] = $this->getTimestamp();

        return $this->update(TB_CONVERSATION, $fields_values, $where_fields_values);
    }

    /**
     * Select cached shortened URL from the database
     *
     * @deprecated Botan.io service is no longer working
     * @param string $url
     * @param string $user_id
     *
     * @return array|bool
     * @throws TelegramException
     */
    public function selectShortUrl($url, $user_id)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        try {
            $sth = $this->pdo->prepare('
                SELECT `short_url`
                FROM `' . TB_BOTAN_SHORTENER . '`
                WHERE `user_id` = :user_id
                  AND `url` = :url
                ORDER BY `created_at` DESC
                LIMIT 1
            ');

            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':url', $url);
            $sth->execute();

            return $sth->fetchColumn();
        } catch (Exception $e) {
            throw new TelegramException($e->getMessage());
        }
    }

    /**
     * Insert shortened URL into the database
     *
     * @deprecated Botan.io service is no longer working
     *
     * @param string $url
     * @param string $user_id
     * @param string $short_url
     *
     * @return bool
     * @throws TelegramException
     */
    public function insertShortUrl($url, $user_id, $short_url)
    {
        if (!$this->isDbConnected()) {
            return false;
        }

        try {
            $sth = $this->pdo->prepare('
                INSERT INTO `' . TB_BOTAN_SHORTENER . '`
                (`user_id`, `url`, `short_url`, `created_at`)
                VALUES
                (:user_id, :url, :short_url, :created_at)
            ');

            $sth->bindValue(':user_id', $user_id);
            $sth->bindValue(':url', $url);
            $sth->bindValue(':short_url', $short_url);
            $sth->bindValue(':created_at', $this->getTimestamp());

            return $sth->execute();
        } catch (Exception $e) {
            throw new TelegramException($e->getMessage());
        }
    }
}
