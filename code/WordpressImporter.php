<?php

/**
 * Wordpress import class which handles the actual import.
 *
 * @package    silverstripe
 * @subpackage bloggerimport
 *
 * @author     Michael Strong <micmania@hotmail.co.uk>
 **/
class WordpressImporter extends SS_Object
{

    /**
     * PHPs max execution time for the import. This will only be set
     * temporarily.
     *
     * @var int
     **/
    private static $max_execution_time = 120;


    /**
     * When set to true, a dry run won't write to the database.
     *
     * @var boolean
     **/
    protected $dryRun = false;


    /**
     * Stores the current xml export file.
     *
     * @var array
     **/
    protected $file;


    /**
     * Stores the current blog we're importing for.
     *
     * @var Blog
     **/
    protected $blog;


    /**
     * Whether or not to import images.
     *
     * @var boolean
     **/
    protected $importAssets = false;


    /**
     * Stores XML namespaces.
     *
     * @var array
     **/
    protected $namespaces = array();


    /**
     * Stores the current categories so that we can check for duplicates.
     *
     * @var array
     **/
    protected $categories = array();


    /**
     * Stores a list of current tags to check for duplicates.
     *
     * @var array
     **/
    protected $tags = array();


    /**
     * Stores a list of current posts.
     *
     * @var array
     **/
    protected $posts = array();


    /**
     * Stores a list of new objects that we've created.
     *
     * @var array
     **/
    protected $created = array();


    /**
     * Stores the SimpleXMLElement from the xml file.
     *
     * @var SimpleXMLElement
     **/
    public $simpleXml;

    /**
     * Stores the authors temporarily
     * @var array
     */
    protected $authors = array();


    public function import()
    {
        // Temporarily set a max execution time.
        $maxExecutionTime = ini_get("max_execution_time");
        ini_set("max_execution_time", (int)$this->config()->get("max_execution_time"));

        // Temporary
        $this->blog = Blog::get()->first();
        $this->setup();
        $created = $this->parse($this->getFile());

        // Reset the max execution time.
        ini_set("max_execution_time", $maxExecutionTime);

        return $created;
    }


    /**
     * Setup the importer.
     **/
    public function setup()
    {
        // Setup categories
        $categories = BlogCategory::get()
            ->filter("BlogID", $this->getBlog()->ID);
        if ($categories) {
            foreach($categories as $category) {
                $this->categories[$category->Title] = $category;
            }
        }

        // Setup tags
        $tags = BlogTag::get()
            ->filter("BlogID", $this->getBlog()->ID);
        if ($tags) {
            foreach($tags as $tag) {
                $this->tags[$tag->Title] = $tag;
            }
        }

        // Setup posts
        $posts = BlogPost::get()
            ->filter("ParentID", $this->getBlog()->ID);
        if ($posts) {
            foreach($posts as $post) {
                $this->posts[$post->WordpressID] = $post;
            }
        }

        $importAssets = Config::inst()->get('BlogImport', 'ImportAssets');
        if (!empty($importAssets)) {
            $this->setImportAssets($importAssets);
        }

        $this->extend("setup");
    }


    /**
     * Entrypoint for a dry run.
     *
     * @return SS_HTTPResponse
     **/
    public function dryRun()
    {
        $this->dryRun = true;
        return $this->import();
    }


    /**
     * Set the XML file to be imported.
     *
     * @return WordpressImporter
     **/
    public function setFile($filename)
    {
        $filename = Director::baseFolder() . '/' . $filename;
        if (file_exists($filename)) {
            $this->file = (string)$filename;
            return $this;
        } else {
            throw new InvalidArgumentException("Unable to find file: " . $filename);
        }
    }


    /**
     * Get the target file.
     *
     * @return string
     **/
    public function getFile()
    {
        return $this->file;
    }


    /**
     * Checks if we're running a dry run.
     *
     * @return boolean
     **/
    public function isDryRun()
    {
        return (boolean)$this->dryRun;
    }


    /**
     * Returns the current blog
     *
     * @return Blog
     **/
    public function getBlog()
    {
        return $this->blog;
    }


    /**
     * Adds an object type to the list to be imported.
     *
     * @param $object string
     **/
    public function addImportedObject($type, $object)
    {
        if (!array_key_exists($type, $this->created)) {
            $this->created[(string)$type] = array();
        }
        $this->created[$type][] = $object;
    }


    /**
     * Returns an imported object.
     *
     * @param $object string
     *
     * @return array
     **/
    public function getImportObject($object)
    {
        return $this->created[(string)$object];
    }


    /**
     * Gets a list of created objects.
     *
     * @return array
     **/
    public function getImported()
    {
        return $this->created;
    }


    /**
     * Set whether to import images or not.
     *
     * @param $bool boolean
     **/
    public function setImportAssets($bool)
    {
        $this->importAssets = (boolean)$bool;
    }


    /**
     * Get whether or not were importing images.
     *
     * @return boolean
     **/
    public function getImportAssets()
    {
        return (boolean)$this->importAssets;
    }


    /**
     * Main entry point for the parser.
     *
     * @param $file string
     **/
    public function parse($file)
    {
        if (file_exists($file)) {
            $this->simpleXml = simplexml_load_file($file) or die('Cannot open file.');
            $this->namespaces = $this->simpleXml->getNamespaces(TRUE);

            $this->importAuthors();
            $this->importBlogCategory();
            $this->importBlogTag();
            $this->importBlogPost();
            $this->importAssets();

            $this->extend("importExtras");

            $data = array();
            if (!empty($this->created)) {
                foreach ($this->created as $type => $created) {
                    $title = $type::create()->i18n_plural_name();
                    $data[$type] = array(
                        "Count" => count($created),
                        "Title" => $title,
                    );
                }
            }
            return $data;
        }
        return array();
    }

    /**
     * Parses xml in $simple_xml to array of blog authors
     * @return array
     */
    public function importAuthors()
    {
        foreach ($this->simpleXml->channel->children($this->namespaces['wp'])->author as $author) {

            if (!$firstName = (string)$author->author_first_name) {
                $firstName = (string)$author->author_display_name;
            }

            $username = (string)$author->author_login;

            if (class_exists('BlogAuthor')) {
                $blogAuthors = BlogAuthor::get()->filter('Username', (string)$username);
                $blogAuthor = $blogAuthors->first();
                if (!$blogAuthor) {
                    $blogAuthor = new BlogAuthor();
                    $blogAuthor->DisplayName = (string)$firstName . ' ' . $author->author_last_name;
                    $blogAuthor->Username = $username;
                    $blogAuthor->Email = (string)$author->author_email;
                    $blogAuthor->write();
                }

                $this->authors[$username] = $blogAuthor;
            }
        }

        return $this->authors;
    }


    /**
     * Imports blog categories. Skips any duplicates.
     *
     * @param $element    SimpleXMLElement
     * @param $namespaces array
     **/
    public function importBlogCategory()
    {

        foreach ($this->simpleXml->channel->children($this->namespaces['wp'])->category as $category) {
            $title = trim((string)$category->cat_name);
            if (!array_key_exists($title, $this->categories)) {
                $cat = BlogCategory::create();
                $cat->URLSegment = trim($category->category_nicename);
                $cat->Title = $title;
                $cat->WordpressID = trim((string)$category->term_id);
                $cat->BlogID = $this->getBlog()->ID;

                // Add hook to update blog category
                $this->extend("beforeImportBlogCategory", $cat);

                if (!$this->isDryRun()) {
                    $cat->write();
                }

                $this->categories[$cat->Title] = $cat;
                $this->addImportedObject("BlogCategory", $cat);
            } else {
                $this->categories[$title] = BlogCategory::get()
                    ->filter("Title", $title)
                    ->filter("BlogID", $this->getBlog()->ID)
                    ->first();
            }
        }

    }


    /**
     * Import BlogTag objects.
     *
     * @param $element    SimpleXMLElement
     * @param $namespaces array
     **/
    public function importBlogTag()
    {

        foreach ($this->simpleXml->channel->children($this->namespaces['wp'])->tag as $tag) {
            $title = trim((string)$tag->tag_name);
            if (!array_key_exists($title, $this->tags)) {
                $t = BlogTag::create();
                $t->URLSegment = trim($tag->tag_slug);
                $t->Title = $title;
                $t->WordpressID = trim((string)$tag->term_id);
                $t->BlogID = $this->getBlog()->ID;

                // Add hook to update blog tag
                $this->extend("beforeImportBlogTag", $t);

                if (!$this->isDryRun()) {
                    $t->write();
                }

                $this->tags[$t->Title] = $t;
                $this->addImportedObject("BlogTag", $t);
            } else {
                $this->tags[$title] = BlogTag::get()->filter("Title", $title)
                    ->filter("BlogID", $this->getBlog()->ID)
                    ->first();
            }
        }
    }

    /**
     * Import the blog posts and link them up to any attachments.
     *
     * @param $element SimpleXMLElement
     **/
    public function importBlogPost()
    {
        foreach ($this->simpleXml->channel->children()->item as $item) {
            $content = $item->children($this->namespaces['content'])->encoded;
            $excerpt = $item->children($this->namespaces['excerpt'])->encoded;
            $post = $item->children($this->namespaces['wp']);
            $dc = $item->children($this->namespaces['dc']);

            // Check for existing post
            if ($post->post_type == "post" && !array_key_exists((string)$post->post_id, $this->posts)) {

                $metaDescription = '';
                $metaTitle = '';

                $postMeta = $post->postmeta;
                if (is_iterable($postMeta)) {
                    foreach ($postMeta as $postMetaItem) {
                        $key = (string) $postMetaItem->meta_key;
                        $value = (string) $postMetaItem->meta_value;

                        if ('_yoast_wpseo_title' === $key) {
                            $metaTitle = $value;
                        } else if ('_yoast_wpseo_metadesc' === $key) {
                            $metaDescription = $value;
                        }
                    }
                }

                $authorID = null;
                if (!empty($this->authors[(string)$dc->creator])) {
                    $authorID = $this->authors[(string)$dc->creator]->ID;
                }

                // work in Stage mode first
                $old = Versioned::get_reading_mode();
                Versioned::set_reading_mode('Stage.Stage');

                $blogPost = BlogPost::create();
                $blogPost->AuthorID = $authorID;
                $blogPost->Title = (string)$item->title;
                $blogPost->MetaDescription = !empty($metaDescription) ? $metaDescription : (string)$excerpt;
                $blogPost->MetaTitle = !empty($metaTitle) ? $metaTitle : (string)$item->title;
                $blogPost->URLSegment = (string)$post->post_name;
                $blogPost->setCastedField("PublishDate", (string)$post->post_date_gmt);
                $blogPost->Content = (string)$this->parseHTML($content);
                $blogPost->WordpressID = (string)$post->post_id;
                $blogPost->ParentID = $this->getBlog()->ID;

                // Hook to update BlogPost
                $this->extend("beforeImportBlogPost", $item, $blogPost);

                if (!$this->isDryRun()) {
                    $blogPost->write();

                    // update title again; otherwise the title is "New blog post"
                    $blogPost->Title = (string)$item->title;
                    $blogPost->writeWithoutVersion(); // no version increase please

                    if ((string)$post->status == "publish") {
                        $blogPost->publish("Stage", "Live");
                    }
                }

                // restore the reading mode to the original
                Versioned::set_reading_mode($old);

                $this->posts[$blogPost->WordpressID] = $blogPost;
                $this->addImportedObject("BlogPost", $blogPost);

                // Add attachments
                $this->addAttachments($item, $blogPost);

            }
        }
    }


    /**
     * Imports images and other uploaded files.
     *
     * @param $element SimpleXMLElement
     **/
    public function importAssets()
    {
        if ($this->getImportAssets()) {
            foreach ($this->simpleXml->channel->children()->item as $item) {
                $attachment = $item->children($this->namespaces['wp']);

                $url = (string)$attachment->attachment_url;
                if ($url) {
                    $split = explode("/", $url);
                    $filename = end($split);
                    $reversed = array_reverse($split);
                    if (count($reversed) > 2) {
                        $month = $reversed[1];
                        $year = $reversed[2];

                        $dir = Controller::join_links("Uploads", $year, $month);
                    } else {
                        $dir = Controller::join_links('Imported');
                    }

                    if (!$this->isDryRun()) {
                        /** @var Folder $folder */
                        $folder = Folder::find_or_make($dir);

                        $filename = Controller::join_links(Director::baseFolder(), $folder, $filename);
                        if (!file_exists($filename)) {
                            // Lets get that image!
                            if (@copy($url, $absPath)) {
                                $imageName = basename($absPath);

                                if ($id = $folder->constructChild($imageName)) {
                                    /** @var File $file */
                                    $file = File::get()->byID($id);
                                    $filename = Controller::join_links(Director::baseFolder(), $folder, $file->getFilename());
                                }
                            }

                            $this->addImportedObject("File", $filename);
                        }
                    }

                    // By now we should have a file that exists
                    if (file_exists($filename)) {
                        $file = Image::get()
                            ->filter("Filename", ltrim(Director::makeRelative($filename), '/'))
                            ->first();

                        // Set the blog post featured image.
                        if ($file) {
                            $postParent = (string)$attachment->post_parent;

                            // Get the post ID
                            $id = array_key_exists((int)$attachment->post_parent, $this->posts)
                                ? $this->posts[(int)$attachment->post_parent] : false;

                            // And get the post
                            if (is_int($id)) $blogPost = BlogPost::get()->byId($id);
                            else $blogPost = null;

                            if ($blogPost) {
                                $blogPost->FeaturedImageID = $file->ID;

                                if (!$this->isDryRun()) {
                                    $isPublished = $blogPost->isPublished();
                                    $blogPost->writeToStage("Stage");
                                    if ($isPublished == "publish") {
                                        $blogPost->publish("Stage", "Live");
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }


    /**
     * Add post attachment (ie tags, categories etc.)
     *
     * @param $element  SimpleXMLElement
     * @param $blogPost BlogPost
     **/
    public function addAttachments(SimpleXMLElement $item, BlogPost $blogPost)
    {

        // Add categories and tags
        foreach ($item->category as $element) {
            if ($element['domain'] == "post_tag") {
                // Get the tag.
                $title = trim((string)$element);
                if (isset($this->tags[$title])) {
                    if (!$this->isDryRun()) {
                        $blogPost->Tags()->add($this->tags[$title]);
                    }
                } else {
                    $tag = BlogTag::create();
                    $tag->Title = $title;
                    $tag->URLSegment = (string)$element['nicename'];
                    $tag->BlogID = $this->getBlog()->ID;

                    // Add hook to update blog tag
                    $this->extend("beforeImportBlogTag", $element, $tag);

                    if (!$this->isDryRun()) {
                        $tag->write();
                        $blogPost->Tags()->add($tag);
                    }

                    $this->tags[$tag->Title] = $tag;
                    $this->addImportedObject("BlogTag", $tag);
                }
            } else if ($element['domain'] == "category") {
                $title = trim((string)$element);
                if (isset($this->categories[$title])) {
                    if (!$this->isDryRun()) {
                        $blogPost->Categories()->add($this->categories[$title]);
                    }
                } else {
                    $category = BlogCategory::create();
                    $category->Title = $title;
                    $category->URLSegment = (string)$element['nicename'];
                    $category->BlogID = $this->getBlog()->ID;

                    // Add hook to update blog category
                    $this->extend("beforeImportBlogCategory", $element, $category);

                    if (!$this->isDryRun()) {
                        $category->write();
                        $blogPost->Categories()->add($category);
                    }

                    $this->categories[$category->Title] = $category;
                    $this->addImportedObject("BlogCategory", $category);
                }
            }
        }

        //Add attachments hook
        $this->extend("addAttachments", $item, $blogPost);
    }


    /**
     * Parses and cleans up the body of the wordpress blog post
     *
     * @param mixed $content The XML object containing the wordpress post body
     *
     * @return string The parsed content block in HTML format
     */
    public function parseHTML($content)
    {
        $fetchImageRegex = '/(http(s?):\/\/[\w\.\/]+)?\/wp-content\/uploads\/(\d{4})\/(\d{2})\/([A-Za-z0-9-_]+)\.(jpg|png|gif|bmp|jpeg)/i';

        // Convert wordpress-style image links to silverstripe asset filepaths
        $locationBlogImages = Config::inst()->get('BlogImport', 'BlogImageFolder');
        if (empty($locationBlogImages)) {
            $locationBlogImages = '/assets/blog/';
        }

        $process = [];

        preg_match_all($fetchImageRegex, $content, $matches);

        if (!empty($matches[0])) {
            foreach ($matches[0] as $imageURL) {
                // avoid duplicate images
                if (!in_array($imageURL, $process, true)) {
                    $process[] = $imageURL;
                }
            }

            foreach ($process as $image) {
                $imageName = $this->copyImageToAssets($image, $locationBlogImages);
            }

        }

        // replace to new URLs anyway
        $replaceImageRegex = '/(http(s?):\/\/[\w\.\/]+)?\/wp-content\/uploads\/(\d{4})\/(\d{2})/i';
        $content = preg_replace($replaceImageRegex, $locationBlogImages, $content);

        // Split multi-line blocks into paragraphs
        $split = preg_split('/\s*\n\s*\n\s*/im', $content);
        $content = '';
        foreach ($split as $paragraph) {
            $paragraph = trim($paragraph);
            if (empty($paragraph))
                continue;

            if (preg_match('/^<p>.*/i', $paragraph))
                $content .= $paragraph;
            else
                $content .= "<p>$paragraph</p>";
        }

        return $content;
    }

    private function copyImageToAssets($remote, $dir)
    {
        $url = (string)$remote;
        $split = explode("/", $url);
        $filename = end($split);

        if (strpos($dir, '/assets/') === 0) {
            $dir = substr($dir, strlen('/assets/'));
        }

        /** @var Folder $folder */
        $folder = Folder::find_or_make($dir);
        $folderName = $folder->Filename;

        $absPath = Controller::join_links(Director::baseFolder(), $folderName, $filename);

        if (!file_exists($absPath)) {
            $check = File::get()->filter('Filename', $folderName . $filename);
            foreach ($check as $item) {
                $item->delete();
            }

            // Lets get that image!
            if (@copy($url, $absPath)) {
                $imageName = basename($absPath);

                if ($id = $folder->constructChild($imageName)) {
                    /** @var File $file */
                    $file = File::get()->byID($id);
                    return $file->getFilename();
                }
            }
        }

        return $folderName . $filename;
    }

}
