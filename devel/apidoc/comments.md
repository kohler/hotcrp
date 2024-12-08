# Comments

These endpoints query and modify submission comments.

Each comment has a *visibility* and a *topic* (which in the UI is called a
*thread*). These values control who can see the comment.

The default comment visibility is `"rev"`, which makes the comment visible to
PC and external reviewers. Other values are `"admin"` (visible only to
submission administrators and the comment author), `"pc"` (visible to PC
reviewers, but not external reviewers), and `"au"` (visible to authors and
reviewers).

The default comment topic is `"rev"`, the review thread. Comments on the
review thread are visible to users who can see reviews; if you can’t see
reviews, you can’t see the review thread. Other comment topics are `"paper"`,
the submission thread (visible to anyone who can see the submission), and
`"dec"`, the decision thread (visible to users who can see the submission’s
decision).


# get /{p}/comment

> Retrieve comment

The `c` parameter specifies the comment to return. If the comment exists and
the user can view it, it will be returned in the `comment` component of the
response. Otherwise, an error response is returned.

If `c` is omitted, all viewable comments are returned in a `comments` list.

* param content boolean: False omits comment content from response


# post /{p}/comment

> Create, modify, or delete comment

The `c` parameter specifies the comment to modify. It can be a numeric comment
ID; `new`, to create a new comment; or `response` (or a compound like
`R2response`), to create or modify a named response.

Setting `delete=1` deletes the specified comment, and the response does not
contain a `comment` component. Otherwise the comment is created or modified,
and the response `comment` component contains the new comment.

Comment attachments may be uploaded as files (requiring a request body in
`multipart/form-data` encoding), or using the [upload API](#operation/upload).
To upload a single new attachment:

* Set the `attachment:1` body parameter to `new`
* Either:
	* Set `attachment:1:file` as a uploaded file containing the relevant data
	* Or use the [upload API](#operation/upload) to upload the file,
	  and supply the upload token in the `attachment:1:upload` body parameter

To upload multiple attachments, number them sequentially (`attachment:2`,
`attachment:3`, and so forth). To delete an existing attachment, supply its
`docid` as an `attachment:N` parameter, and set `attachment:N:delete` to 1.

* param override boolean
* param delete boolean
* param text string
* param tags string
* param topic comment_topic
* param visibility comment_visibility
* param response string
* param ready boolean
* param draft boolean
* param blind boolean
* param by_author boolean
* param review_token string
