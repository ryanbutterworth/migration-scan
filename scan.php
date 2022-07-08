<?php
    /**
     * Migration scanner for MySQL 8.0 / MariaDB 10.5 compatibility
     * Use alongside Jenkins scan for CMS 21 / XFP 8.2 upgrades
     * For an easy overview of potential issues with reserved words

     * Usage:
     * php scan.php /path/to/customer/repo

     * Returns a list of all migrations with their usage of reserved words, including line number
     */

    require "vendor/autoload.php";
    use PHPHtmlParser\Dom;
    use PHPHtmlParser\Selector\Selector;
    use PHPHtmlParser\Selector\Parser;

    const DS = DIRECTORY_SEPARATOR;

    function getWebFile($url)
    {
        $user_agent='Mozilla/5.0 (Windows NT 6.1; rv:8.0) Gecko/20100101 Firefox/8.0';

        $options = array(

            CURLOPT_CUSTOMREQUEST       => "GET",       //set request type post or get
            CURLOPT_POST                => false,       //set to GET
            CURLOPT_USERAGENT           => $user_agent, //set user agent
            CURLOPT_COOKIEFILE          =>"cookie.txt", //set cookie file
            CURLOPT_COOKIEJAR           =>"cookie.txt", //set cookie jar
            CURLOPT_RETURNTRANSFER      => true,        // return web page
            CURLOPT_HEADER              => false,       // don't return headers
            CURLOPT_FOLLOWLOCATION      => true,        // follow redirects
            CURLOPT_ENCODING            => "",          // handle all encodings
            CURLOPT_AUTOREFERER         => true,        // set referer on redirect
            CURLOPT_CONNECTTIMEOUT      => 120,         // timeout on connect
            CURLOPT_TIMEOUT             => 120,         // timeout on response
            CURLOPT_MAXREDIRS           => 10,          // stop after 10 redirects
            CURLOPT_SSL_VERIFYHOST      => false,       // stop after 10 redirects
            CURLOPT_SSL_VERIFYPEER      => false,       // stop after 10 redirects
        );

        $ch      = curl_init($url);
        curl_setopt_array($ch, $options);
        $content = curl_exec($ch);
        $err     = curl_errno($ch);
        $errmsg  = curl_error($ch);
        $header  = curl_getinfo($ch);
        curl_close( $ch );

        $header['errno']   = $err;
        $header['errmsg']  = $errmsg;
        $header['content'] = $content;
        return $header;
    }

    function joinPaths() {
        $paths = array();
    
        foreach (func_get_args() as $arg) {
            if ($arg !== '') { $paths[] = $arg; }
        }
    
        return preg_replace('#' . DS . '+#', DS, join(DS, $paths));
    }

    function stringEndsWith($haystack,$needle,$case=true) {
        $expectedPosition = strlen($haystack) - strlen($needle);
        if ($case){
            return strrpos($haystack, $needle, 0) === $expectedPosition;
        }
        return strripos($haystack, $needle, 0) === $expectedPosition;
    }
    
    function sortLiterals($a, $b) {
        if ($a[0] == $b[0]) {
            return 0;
        }

        return ($a[0] < $b[0]) ? -1 : 1;
    }

    ///////////////
    // Start CLI //
    ///////////////

    $repoPath = isset($argv[1]) ? rtrim(str_replace(DS == "/" ? "\\" : "/", DS, $argv[1]), DS) : false;

    if (!$repoPath)
    {
        print "ERROR: You must provide the path to your local customer repository";
        exit;
    }

    $migrationsPath = joinPaths($repoPath, "upgrades", "migrations");

    if (!is_dir($repoPath) || !is_dir($migrationsPath))
    {
        printf("ERROR: Invalid repository, check the path is correct (%s = %s)" . PHP_EOL, $repoPath, $migrationsPath);
        exit;
    }

    printf("Valid repository found (%s = %s)" . PHP_EOL, $repoPath, $migrationsPath);
    
    $cachedReservedWordsPath = joinPaths(getcwd(), ".cache");
    $literalString = "";
    
    if (!is_file($cachedReservedWordsPath))
    {
        $keywordsURL = "http://dev.mysql.com/doc/refman/8.0/en/keywords.html";
        printf("Cache not found at %s - fetching reserved words from %s" . PHP_EOL, $cachedReservedWordsPath, $keywordsURL);

        $response = getWebFile($keywordsURL);

        if (!empty($response["errno"])) {
            printf("Error fetching reserved words [%s] %s", $response["errno"], $response["errmsg"]);
            exit;
        }

        $dom = new Dom;
        $dom->loadStr($response["content"]);

        $selector = new Selector("li.listitem > p", new Parser());
        $nodes = $selector->find($dom->find("html")[0]);

        foreach ($nodes as $key => $node) {
            if (stringEndsWith($node->innerHTML, "(R)")) {
                preg_match("/>(.+)</", $node->innerHTML, $matches);

                if (isset($matches[1])) {
                    $literalString .= $matches[1] . PHP_EOL;
                }
            }
            else {
                unset($nodes[$key]);
            }
        }

        $cacheFile = fopen(".cache", "w");
        fwrite($cacheFile, $literals);
        fclose($cacheFile);
    }
    else {
        $literalString = file_get_contents($cachedReservedWordsPath);
    }

    $literals = explode(PHP_EOL, $literalString);
    $literalsCount = count($literals);

    if (empty($literals[$literalsCount - 1])) {
        unset($literals[$literalsCount - 1]);
        --$literalsCount;
    }

    printf("Found %d reserved word definitions - checking migrations..." . PHP_EOL . PHP_EOL, $literalsCount);

    $directoryIterator = new DirectoryIterator($migrationsPath);
    foreach ($directoryIterator as $fileInfo) {
        $fileName = $fileInfo->getFilename();
        if (strpos($fileName, "Version") !== false) {
            $literalMatches = [];
            $variations = [
                [null, null],
            ];

            printf(PHP_EOL . "////////////////////////////////////////////////////" . PHP_EOL . "//// Checking migration %s" . PHP_EOL . "////////////////////////////////////////////////////" . PHP_EOL . PHP_EOL, $fileName);

            $migrationContent = file_get_contents($fileInfo->getPathname());

            foreach ($literals as $literal) {
                $literal = strtolower($literal);
                $lineNumber = false;

                foreach ($variations as $variation) {
                    $variedLiteral = ($variation[0] ?? "") . $literal . ($variation[1] ?? "");
                    $handle = fopen($fileInfo->getPathname(), "r");
                    
                    preg_match_all("/.*['\"]+.*(\b" . preg_quote($variedLiteral) . "\b).*['|\"]+.*/", $migrationContent, $matches, PREG_OFFSET_CAPTURE);
                    if (count($matches) > 0) {
                        foreach ($matches[0] as $match) {
                            $literalMatches[] = [$literal, $match[1], trim($match[0])];
                        }
                    }
                }
            }

            usort($literalMatches, "sortLiterals");

            foreach ($literalMatches as $match) {
                printf("[%s] %d: %s" . PHP_EOL, $match[0], $match[1], $match[2]);
            }
        }
    }