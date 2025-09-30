<?php
// api_reaction.php -- HotCRP reaction API call
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

class Reaction_API {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $user;
    /** @var PaperInfo */
    private $prow;
    /** @var CommentInfo */
    private $comment;
    /** @var MessageSet */
    private $ms;

    function __construct(Contact $user, PaperInfo $prow, CommentInfo $comment) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->prow = $prow;
        $this->comment = $comment;
        $this->ms = new MessageSet;
    }

    /** @return JsonResult */
    private function run_qreq(Qrequest $qreq) {
        // Check if reactions are enabled
        if (!$this->conf->setting("cmt_reactions", 1)) {
            return JsonResult::make_error(404, "<0>Comment reactions are disabled");
        }

        // Check if user can view the comment
        if (!$this->user->can_view_comment($this->prow, $this->comment, true)) {
            return JsonResult::make_error(403, "<0>You aren't allowed to view that comment");
        }

        // Check if user can react (same permission as viewing comment)
        if (!$this->user->can_view_comment($this->prow, $this->comment)) {
            return JsonResult::make_error(403, "<0>You aren't allowed to react to that comment");
        }

        $emoji = trim($qreq->emoji ?? "");
        if (!$emoji) {
            return JsonResult::make_parameter_error("emoji");
        }

        // Validate emoji (check against emoji list)
        if (!$this->is_valid_emoji($emoji)) {
            return JsonResult::make_error(400, "<0>Invalid emoji");
        }

        if ($qreq->is_post()) {
            return $this->handle_post($emoji);
        } else {
            return $this->handle_get();
        }
    }

    /** @param string $emoji
     * @return bool */
    private function is_valid_emoji($emoji) {
        static $valid_emojis = null;
        if ($valid_emojis === null) {
            $emoji_file = SiteLoader::find("scripts/emojicodes.json");
            if ($emoji_file) {
                $emoji_data = json_decode(file_get_contents($emoji_file), true);
                $valid_emojis = array_keys($emoji_data["emoji"] ?? []);
            } else {
                $valid_emojis = [];
            }
        }
        return in_array($emoji, $valid_emojis);
    }

    /** @param string $emoji
     * @return JsonResult */
    private function handle_post($emoji) {
        // Check if reaction already exists
        $existing_reaction = $this->comment->find_reaction($this->user, $emoji);
        
        if ($existing_reaction) {
            // Remove existing reaction (toggle off)
            $success = $this->comment->remove_reaction($this->user, $emoji);
            if ($success) {
                $this->ms->success("<0>Reaction removed");
                $action = "removed";
            } else {
                return JsonResult::make_error(500, "<0>Failed to remove reaction");
            }
        } else {
            // Add new reaction
            $reaction = $this->comment->add_reaction($this->user, $emoji);
            $success = $reaction->save($this->user, $this->comment);
            if ($success) {
                $this->ms->success("<0>Reaction added");
                $action = "added";
            } else {
                return JsonResult::make_error(500, "<0>Failed to add reaction");
            }
        }

        $jr = new JsonResult(200, ["ok" => true, "action" => $action]);
        $jr["reactions"] = $this->comment->unparse_reactions($this->user);
        if ($this->ms->has_message()) {
            $jr["message_list"] = $this->ms->message_list();
        }
        
        return $jr;
    }

    /** @return JsonResult */
    private function handle_get() {
        $jr = new JsonResult(200, ["ok" => true]);
        $jr["reactions"] = $this->comment->unparse_reactions($this->user);
        return $jr;
    }

    static function run(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        // Get comment ID
        $comment_id = $qreq->c ?? $qreq->comment_id ?? null;
        if (!$comment_id || !ctype_digit($comment_id)) {
            return JsonResult::make_parameter_error("c");
        }

        // Fetch comment
        $comments = $prow->fetch_comments("commentId=" . (int) $comment_id);
        if (empty($comments)) {
            return JsonResult::make_error(404, "<0>Comment not found");
        }

        $comment = $comments[0];
        return (new Reaction_API($user, $prow, $comment))->run_qreq($qreq);
    }
}
