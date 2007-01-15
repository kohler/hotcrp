truncate table PaperFields;
truncate table PaperList;
truncate table PaperListColumns;

insert into PaperFields set fieldId=1, fieldName='id', description='ID';
insert into PaperFields set fieldId=2, fieldName='id', description='ID (manage link)';
insert into PaperFields set fieldId=3, fieldName='id', description='ID (review link)';
insert into PaperFields set fieldId=11, fieldName='title', description='Title';
insert into PaperFields set fieldId=12, fieldName='title', description='Title (manage link)';
insert into PaperFields set fieldId=13, fieldName='title', description='Title (review link)';
insert into PaperFields set fieldId=27, fieldName='status', description='Status';
insert into PaperFields set fieldId=28, fieldName='download', description='Download', sortable=0;
insert into PaperFields set fieldId=29, fieldName='reviewer', description='Reviewer type';
insert into PaperFields set fieldId=30, fieldName='reviewerStatus', description='Reviewer status';
insert into PaperFields set fieldId=31, fieldName='selector', description='Selector';
insert into PaperFields set fieldId=32, fieldName='review', description='Review';
insert into PaperFields set fieldId=33, fieldName='status', description='Status (for reviewers)';
insert into PaperFields set fieldId=34, fieldName='reviewerName', description='Reviewer name';
insert into PaperFields set fieldId=35, fieldName='reviewAssignment', description='Review assignment';
insert into PaperFields set fieldId=36, fieldName='topicMatch', description='Topic interest score';
insert into PaperFields set fieldId=37, fieldName='topicNames', description='Topic names', sortable=0, display=2;
insert into PaperFields set fieldId=38, fieldName='reviewerNames', description='Reviewer names', sortable=0, display=2;
insert into PaperFields set fieldId=39, fieldName='reviewPreference', description='Review preference';
insert into PaperFields set fieldId=40, fieldName='editReviewPreference', description='Edit review preference';
insert into PaperFields set fieldId=41, fieldName='reviewsStatus', description='Review counts';
insert into PaperFields set fieldId=42, fieldName='matches', description='Matches', display=0;
insert into PaperFields set fieldId=43, fieldName='desirability', description='Desirability';
insert into PaperFields set fieldId=44, fieldName='allPreferences', description='Reviewer preferences', sortable=0, display=2;
insert into PaperFields set fieldId=45, fieldName='reviewerTypeIcon', description='Reviewer type';
insert into PaperFields set fieldId=46, fieldName='optOverallMeritIcon', description='Overall merit (icon)';
insert into PaperFields set fieldId=47, fieldName='authorsMatch', description='Authors match', sortable=0, display=2;
insert into PaperFields set fieldId=48, fieldName='collaboratorsMatch', description='Collaborators match', sortable=0, display=2;
insert into PaperFields set fieldId=49, fieldName='selectorOn', description='Selector on';
insert into PaperFields set fieldId=50, fieldName='optAuthors', description='Optional authors', sortable=0, display=3;

insert into PaperList set paperListId=1, paperListName='a',
	description='Authored papers', sortCol=0;
insert into PaperListColumns (paperListId, fieldId, col) values
	(1, 2, 0), (1, 12, 1), (1, 27, 2);

insert into PaperList set paperListId=2, paperListName='s',
	description='Submitted papers', sortCol=0;
insert into PaperListColumns (paperListId, fieldId, col) values
	(2, 31, 0), (2, 1, 1), (2, 11, 2), (2, 45, 3), (2, 41, 4),
	(2, 33, 5), (2, 46, 6), (2, 50, 7);

insert into PaperList set paperListId=3, paperListName='all',
	description='All papers', sortCol=0;
insert into PaperListColumns (paperListId, fieldId, col) values
	(3, 31, 0), (3, 1, 1), (3, 11, 2), (3, 27, 3), (3, 45, 4), (3, 50, 5);

insert into PaperList set paperListId=4, paperListName='authorHome',
	description='My papers (homepage view)', sortCol=0;
insert into PaperListColumns (paperListId, fieldId, col) values
	(4, 2, 0), (4, 12, 1), (4, 27, 2);

insert into PaperList set paperListId=6, paperListName='reviewerHome',
	description='Papers to review (homepage view)', sortCol=0;
insert into PaperListColumns (paperListId, fieldId, col) values
	(6, 3, 0), (6, 13, 1), (6, 45, 2), (6, 33, 3);

insert into PaperList set paperListId=7, paperListName='r',
	description='Papers to review', sortCol=0;
insert into PaperListColumns (paperListId, fieldId, col) values
	(7, 31, 0), (7, 3, 1), (7, 13, 2), (7, 45, 3), (7, 41, 4),
	(7, 33, 5);

insert into PaperList set paperListId=8, paperListName='reviewAssignment',
	description='Review assignments', sortCol=3;
insert into PaperListColumns (paperListId, fieldId, col) values
	(8, 3, 0), (8, 13, 1), (8, 39, 2), (8, 36, 3), (8, 43, 4), 
	(8, 35, 5), (8, 50, 6), (8, 37, 7), (8, 38, 8), (8, 44, 9), 
	(8, 46, 10), (8, 47, 11), (8, 48, 12);

insert into PaperList set paperListId=9, paperListName='editReviewPreference',
	description='Edit reviewer preferences', sortCol=3;
insert into PaperListColumns (paperListId, fieldId, col) values
	(9, 1, 0), (9, 11, 1), (9, 36, 2), (9, 45, 3), (9, 40, 4), 
	(9, 50, 5), (9, 37, 6);

insert into PaperList set paperListId=10, paperListName='reviewers',
	description='Review assignments', sortCol=0;
insert into PaperListColumns (paperListId, fieldId, col) values
	(10, 3, 0), (10, 13, 1), (10, 38, 2);

insert into PaperList set paperListId=11, paperListName='reviewersSel',
	description='Review assignments', sortCol=1;
insert into PaperListColumns (paperListId, fieldId, col) values
	(11, 49, 0), (11, 3, 1), (11, 13, 2), (11, 38, 3);

insert into PaperList set paperListId=12, paperListName='req',
	description='Papers to review', sortCol=0;
insert into PaperListColumns (paperListId, fieldId, col) values
	(12, 31, 0), (12, 3, 1), (12, 13, 2), (12, 45, 3), (12, 41, 4),
	(12, 33, 5);

delete from Settings where name='paperlist_update';
insert into Settings set name='paperlist_update', value=unix_timestamp(current_timestamp);
