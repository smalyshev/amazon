<?php
require_once 'vendor/autoload.php';

date_default_timezone_set('UTC');
$clean = null;
if (PHP_SAPI == 'cli') {
    define('LISTID', $argv[1]);
    $clean = isset($argv[2]) ? $argv[2] : null;
} else {
    define('LISTID', $_GET['list']);
    $clean = isset($_GET['reload']) ? $_GET['reload'] : null;
}


class AmazonCall
{
    private function log($msg)
    {
        if (PHP_SAPI == 'cli') {
            file_put_contents('php://stderr', $msg."\n");
        }
    }

    public function wishlist($listID, $startpage = 1)
    {
        $size = 100;
        $ret = array();
        $wishlistdom = new DOMDocument();
        // ignore parsing warnings
        @$wishlistdom->loadHTMLFile("http://www.amazon.com/gp/registry/wishlist/$listID?disableNav=1&page=$startpage");
        $this->log("Loaded page $startpage");
        $wishlistxpath = new DOMXPath ($wishlistdom);
        // I want to be able to limit and rearrange the list, so I turn it into an array
        $items = iterator_to_array($wishlistxpath->query("//div[starts-with(@id,'item_')]"));
        // filter $items as desired, then pull out the data
        foreach ($items as $item) {
            $link = $wishlistxpath->evaluate(".//a[starts-with(@id, 'itemName')]", $item)->item(0);
            $href = $link->attributes->getNamedItem('href')->nodeValue;
            if (preg_match('|/dp/\w+|', $href, $matches)) {
                $href = "http://amazon.com$matches[0]"; // simplify the URL
            } else {
                $href = "http://amazon.com$href";
            }
            $title = $link->textContent;
            $author = $link->parentNode->nextSibling->textContent;
            $image = $wishlistxpath->query(".//img", $item)->item(0)->attributes->getNamedItem('src')->nodeValue;
            if (preg_match('|http://ecx.images-amazon.com/images/I/[^.]+|', $image, $matches)) {
                $image = $matches[0] . "._SL$size.jpg";
            } else {
                $image = "http://ecx.images-amazon.com/images/G/01/x-site/icons/no-img-sm._SL${size}_.jpg";
            }
            $author = trim($author);
            if (preg_match('|\s*by\s+(.*?)\s*\([^)]+\)|i', $author, $m)) {
                $author = $m[1];
            }
            if (preg_match('|http://amazon.com/dp/(\d+)|i', $href, $m)) {
                $isbn = $m[1];
            } else {
                $isbn = null;
            }
            $ret[] = ["author" => trim($author), "title" => trim($title), "image" => $image,
                "amazon" => $href, "ISBN" => $isbn];
        }
        if ($wishlistxpath->evaluate("count(//li[@class='a-last'])")) { // this is the "Next->" button
            $ret = array_merge($ret, $this->cache->wishlist($listID, $startpage + 1));
        }
        $this->log("Done page $startpage");
        return $ret;
    }

    public function allISBN($main_isbn)
    {
        $url = "http://www.librarything.com/api/thingISBN/$main_isbn";
        $this->log("Checking main $main_isbn");
        $xml = simplexml_load_file($url);
        $isbns = [];
        foreach ($xml->isbn as $isbn) {
            $lang = file_get_contents("http://www.librarything.com/api/thingLang.php?isbn=$isbn");
            if ($lang == "eng") {
                $isbns[] = (string)$isbn;
            }
        }
        return array_unique($isbns);
    }

    function get_SJ($isbn)
    {
        $this->log("Checking $isbn");
        $url = "http://mill1.sjlibrary.org/search/i$isbn";
        $data = file_get_contents($url);
        return strstr($data, "No matches found") == false;
    }

    function get_lib_all($func, $isbns)
    {
        foreach ($isbns as $isbn) {
            $res = $this->cache->$func($isbn);
            if ($res) {
                return $isbn;
            }
        }
        return false;
    }

}

$myamazon = new AmazonCall();

$fe_opt = array('cached_entity' => $myamazon, "lifetime" => 8640000); // lifetime 100 days
$be_opt = array('cache_dir' => dirname(__FILE__) . "/cache/", 'file_locking' => false, "hashed_directory_level" => 1);
$cache = Zend_Cache::factory('Class', 'File', $fe_opt, $be_opt);

$myamazon->cache = $cache;

//$cache->clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG, array('ISBN'));
//$cache->clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG, array('Amazon'));
//$cache->clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG, array('Library'));
//$cache->clean(Zend_Cache::CLEANING_MODE_ALL);

if ($clean) {
    $cache->clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG, [$clean]);
}
$cache->setTagsArray(array('Amazon', 'AmazonList'));
$list = $cache->wishlist(LISTID);

foreach ($list as &$item) {
    $cache->setTagsArray(array('ISBN'));
    if (isset($item['ISBN'])) {
        $item['isbns'] = $cache->allISBN($item['ISBN']);
    } else {
        $item['isbns'] = [];
    }
    $cache->setTagsArray(array('Library'));
    $item["SC"] = null;
    if (!empty($item['isbns'])) {
        $item["SJ"] = $cache->get_lib_all("get_SJ", $item['isbns']);
    } else {
        $item["SJ"] = null;
    }
}
function book_cmp($a, $b)
{
    return strcasecmp($a['title'], $b['title']);
}

usort($list, "book_cmp");
$v = new Zend_View();
$v->setScriptPath(dirname(__FILE__));
$v->books = $list;
echo $v->render('view.phtml');
