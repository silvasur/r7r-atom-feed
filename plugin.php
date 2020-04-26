<?php

class atom_feed extends RatatoeskrPlugin
{
    private $config;
    private $config_modified;

    public function pluginpage(&$data, $url_now, &$url_next)
    {
        $this->prepare_backend_pluginpage();

        $url_next = array();

        if(isset($_POST["save_config"]))
        {
            if(preg_match("/^[a-zA-Z0-9\\-_]+\$/", $_POST["path"]) == 0)
                $this->ste->vars["error"] = "Invalid URL Path. Must be at least one character long and may only consist of: a-z, A-Z 0-9 - _";
            elseif(empty($_POST["author_name"]))
                $this->ste->vars["error"] = "Author name must be set.";
            else
            {
                $this->config["title"]     = $_POST["title"];
                $this->config["path"]      = $_POST["path"];
                $this->config["unique_id"] = $_POST["baseid"];
                $this->config["author"]    = array(
                    "name"  => $_POST["author_name"],
                    "email" => $_POST["author_email"],
                    "uri"   => $_POST["author_uri"]
                );

                $this->config_modified = True;

                $this->ste->vars["success"] = "Configuration saved.";
            }
        }

        $this->ste->vars["feed_title"]   = $this->config["title"];
        $this->ste->vars["feed_path"]    = $this->config["path"];
        $this->ste->vars["feed_baseid"]  = $this->config["unique_id"];
        $this->ste->vars["feed_author"]  = $this->config["author"];

        echo $this->ste->exectemplate($this->get_template_dir() . "/backend.html");
    }

    public function ste_tag_atom_feed($ste, $params, $sub)
    {
        global $ratatoeskr_settings;

        $feeds = array(array("", "/section/" . $ratatoeskr_settings["default_section"]));
        if(isset($ste->vars["current"]["article"]))
        {
            if($ste->vars["current"]["article"]["comments_allowed"])
                $feeds[] = array("Comments", "/comments/" . $ste->vars["current"]["article"]["id"]);
            $feeds[] = array($ste->vars["current"]["article"]["section"]["title"], "/section/" . $ste->vars["current"]["article"]["section"]["id"]);
        }
        elseif(isset($ste->vars["current"]["section"]) and ($ste->vars["current"]["section"]["id"] != $ratatoeskr_settings["default_section"]))
            $feeds[] = array($ste->vars["current"]["section"]["title"], "/section/" . $ste->vars["current"]["section"]["id"]);

        $feedbase = $this->config["path"];

        return implode("\n", array_map(
            function($f) use ($ste, $feedbase)
            {
                global $rel_path_to_root;
                list($title, $path) = $f;
                return "<link href=\"$rel_path_to_root/$feedbase/" . $ste->vars["language"] . "$path.xml\" type=\"application/atom+xml\" rel=\"alternate\" title=\"" . htmlesc(empty($title) ? "Sitewide" : $title) . "\" />";
            }, $feeds));
    }

    public function feedgenerator(&$data, $url_now, &$url_next)
    {
        global $ratatoeskr_settings, $rel_path_to_root;

        list($lang, $mode, $item_id) = $url_next;
        $url_next = array();
        if(substr($item_id, -4) == ".xml")
            $item_id = substr($item_id, 0, -4) + 0;
        else
            $item_id += 0;

        if(empty($lang) or empty($mode) or empty($item_id))
            throw new NotFoundError();

        if(($mode != "section") and ($mode != "comments"))
            throw new NotFoundError();

        if(!in_array($lang, $ratatoeskr_settings["languages"]))
            throw new NotFoundError();

        $baseurl = explode("/",self_url());
        $baseurl = array_slice($baseurl, 0, -1);
        foreach(explode("/", $rel_path_to_root) as $part)
        {
            if($part == "..")
                $baseurl = array_slice($baseurl, 0, -1);
        }
        $baseurl = implode("/", $baseurl);

        $this->ste->vars["xmlbase"] = self_url();
        $this->ste->vars["baseurl"] = $baseurl;

        $this->ste->vars["feed"] = array(
            "lang"      => $lang,
            "feedpath"  => urlencode($this->config["path"]),
            "title"     => "",
            "alternate" => "",
            "mode"      => $mode,
            "unique_id" => $this->config["unique_id"],
            "id"        => $item_id,
            "author"    => $this->config["author"],
            "updated"   => 0,
            "entries"   => array()
        );

        if($mode == "section")
        {
            try
            {
                $section = Section::by_id($item_id);
                $articles = Article::by_multi(array("section" => $section, "onlyvisible" => True, "langavail" => $lang), "timestamp", "DESC", NULL, NULL, NULL, NULL, $_);
                $this->ste->vars["feed"]["title"]     = $section->title[$lang]->text . " - " . $this->config["title"];
                $this->ste->vars["feed"]["alternate"] = "$baseurl/$lang/" . $section->name;
                $this->ste->vars["feed"]["updated"]   = empty($articles) ? time() : $articles[0]->timestamp;

                $this->ste->vars["feed"]["entries"] = array_map(function($a) use($lang, $rel_path_to_root, $baseurl, $section) { return array(
                    "id"        => $a->get_id(),
                    "title"     => $a->title[$lang]->text,
                    "updated"   => $a->timestamp,
                    "summary"   => textprocessor_apply(str_replace("%root%", $rel_path_to_root, $a->excerpt[$lang]->text), $a->excerpt[$lang]->texttype),
                    "alternate" => "$baseurl/$lang/" . $section->name . "/" . $a->urlname
                ); }, $articles);
            }
            catch(DoesNotExistError $e)
            {
                throw new NotFoundError();
            }
        }
        elseif($mode == "comments")
        {
            try
            {
                $article = Article::by_id($item_id);
                if(!isset($article->title[$lang]))
                    throw new NotFoundError();
                $comments = $article->get_comments($lang, True);
                usort($comments, function($a, $b) { return intcmp($a->get_timestamp(), $b->get_timestamp()); });

                $this->ste->vars["feed"]["title"]     = "Comments for " . $article->title[$lang]->text . " - " . $this->config["title"];
                $this->ste->vars["feed"]["alternate"] = "$baseurl/$lang/" . $article->urlname;
                $this->ste->vars["feed"]["updated"]   = empty($comments) ? $article->timestamp : $comments[count($comments)-1]->get_timestamp();

                $i = 0;
                $this->ste->vars["feed"]["entries"] = array_map(function($c) use (&$i) { $i++; return array(
                    "id"      => $c->get_id(),
                    "title"   => "Comment #" . $i,
                    "updated" => $c->get_timestamp(),
                    "content" => $c->create_html(),
                    "author"  => array("name" => $c->author_name)
                ); }, $comments);
            }
            catch(DoesNotExistError $e)
            {
                throw new NotFoundError();
            }
        }

        header("Content-Type: application/atom+xml; charset=UTF-8");
        echo $this->ste->exectemplate($this->get_template_dir() . "/feed.xml");
    }

    public function init()
    {
        $this->config = $this->kvstorage["config"];
        $this->config_modified = False;

        $this->ste->register_tag("atom_feed", array($this, "ste_tag_atom_feed"));
        $this->register_url_handler($this->config["path"], array($this, "feedgenerator"));
        $this->register_backend_pluginpage("Atom Feed", array($this, "pluginpage"));
    }

    public function install()
    {
        $this->kvstorage["config"] = array(
            "path"      => "atom-feeds",
            "unique_id" => uniqid("", True),
            "author"    => array("name" => "*", "email" => "", "uri" => ""),
            "title"     => "My Feed"
        );
    }

    public function atexit()
    {
        if($this->config_modified)
            $this->kvstorage["config"] = $this->config;
    }
}
