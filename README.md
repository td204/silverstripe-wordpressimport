# Wordpress Import Module
[![Build Status](https://travis-ci.org/camfindlay/silverstripe-wordpressimport.png?branch=master)](https://travis-ci.org/camfindlay/silverstripe-wordpressimport)

## Maintainer Contacts
* Terry Duivesteijn (Nickname: td204) <terry (at) loungeroom.nl>

## Requirements
* mod_rewrite (optional, if you need to cater for old incoming links and are using Apache)
* SilverStripe Framework & CMS 3.1.x
* silverstripe/blog 1.*
* silverstripe/comments

## Installation Instructions

    composer require td204/silverstripe-wordpressimport

This module was forked from: https://github.com/camfindlay/silverstripe-wordpressimport
And altered to work with silverstripe-blog 2.5.

If you are running silverstripe-blog 1.x, please go to the original module (untested in this module). 

NOTE: After upgrading from blog 1.x to 2.x don't forget to run dev/tasks/BlogMigrationTask as indicated in the silverstripe/blog documentation.

### Usage Overview

#### Export WordPress data
In your WordPress admin, go to Tools » Export. Export only the blog posts of your site.

#### Silverstripe
Make sure you have flushed your site (?flush=1).
Go to your Silverstripe admin, edit the main Blog-page in your SiteTree. 
Click on the tab "Import", select the exported XML-file and click "Import Wordpress XML File".


It will change any links to uploaded images and 
files in your posts that follow the convention 
"http://yourdomain.com/wp-content/uploads/yyyy/mm/filesname.jpg" 
to "http://yourdomain.com/assets/Uploads/yyyy/mm/filesname.jpg" 
which allows you to migrate you uploaded images 
and files over to SilverStripe assets folder while maintaining 
images in your posts.

### Optional Rewriting
Add this in your .htaccess file to port old 
wordpress posts in the form /yyyy/mm/name-of-post/
 to new SilverStripe /blog/name-of-post convention.

    RewriteRule ^[0-9]{4}/[0-9]{2}/(.*)$ /blog/$1 [R,L]

## Known issues:
1. Content can lose a lot of the formatting coming from Wordpress.
2. Perhaps parsing the content through a nl2br might help?
3. Image captions need to be catered for and styled otherwise they end up looking like un-parse shortcodes.

