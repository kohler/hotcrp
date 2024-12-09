# get /{p}/tags

> Retrieve submission tags

* response_schema tag_response


# post /{p}/tags

> Change submission tags

* response_schema tag_response
* response_schema search_response


# post /assigntags

> Change several tags

* param =tagassignment string:Comma-separated list of paper IDs and tag assignments
* param ?=search search_parameter_specification
* response_schema search_response


# get /alltags

> Retrieve all visible tags

* response tags tag_list


# get /taganno

> Retrieve tag annotations

* param tag tag
* param ?search search_parameter_specification
* response tag tag
* response editable boolean
* response anno [tag_annotation]
* response_schema search_response


# post /taganno

> Change tag annotations

* param +anno [tag_annotation]
* response tag tag
* response editable boolean
* response anno [tag_annotation]
* response_schema search_response


# get /{p}/tagmessages

> Retrieve tag edit messages


# get /{p}/votereport

> Retrieve vote analysis

* param tag tag
