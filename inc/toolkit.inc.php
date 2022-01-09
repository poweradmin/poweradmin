<?php
/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
 *      <http://www.poweradmin.org/credits.html>
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *  Toolkit functions
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
// TODO: display elapsed time and memory consumption,
// used to check improvements in refactored version
$display_stats = false;
if ($display_stats)
    include('inc/benchmark.php');

ob_start();

require_once("error.inc.php");

if (!function_exists('session_start'))
    die(error('You have to install PHP session extension!'));
if (!function_exists('_'))
    die(error('You have to install PHP gettext extension!'));
if (!function_exists('openssl_encrypt'))
    die(error('You have to install PHP openssl extension!'));

session_start();

include_once("config-me.inc.php");

if (!@include_once("config.inc.php")) {
    error(_('You have to create a config.inc.php!'));
}

/* * ***********
 * Constants *
 * *********** */

if (isset($_GET["start"])) {
    define('ROWSTART', (($_GET["start"] - 1) * $iface_rowamount));
} else {
    /** Starting row
     */
    define('ROWSTART', 0);
}

if (isset($_GET["letter"])) {
    define('LETTERSTART', $_GET["letter"]);
    $_SESSION["letter"] = $_GET["letter"];
} elseif (isset($_SESSION["letter"])) {
    define('LETTERSTART', $_SESSION["letter"]);
} else {
    /** Starting letter
     */
    define('LETTERSTART', "a");
}

if (isset($_GET["zone_sort_by"]) && preg_match("/^[a-z_]+$/", $_GET["zone_sort_by"])) {
    define('ZONE_SORT_BY', $_GET["zone_sort_by"]);
    $_SESSION["zone_sort_by"] = $_GET["zone_sort_by"];
} elseif (isset($_POST["zone_sort_by"]) && preg_match("/^[a-z_]+$/", $_POST["zone_sort_by"])) {
    define('ZONE_SORT_BY', $_POST["zone_sort_by"]);
    $_SESSION["zone_sort_by"] = $_POST["zone_sort_by"];
} elseif (isset($_SESSION["zone_sort_by"])) {
    define('ZONE_SORT_BY', $_SESSION["zone_sort_by"]);
} else {
    /** Field to sort zone by
     */
    define('ZONE_SORT_BY', "name");
}

if (isset($_SESSION["userlang"])) {
    $iface_lang = $_SESSION["userlang"];
}

if (isset($_GET["record_sort_by"]) && preg_match("/^[a-z_]+$/", $_GET["record_sort_by"])) {
    define('RECORD_SORT_BY', $_GET["record_sort_by"]);
    $_SESSION["record_sort_by"] = $_GET["record_sort_by"];
} elseif (isset($_POST["record_sort_by"]) && preg_match("/^[a-z_]+$/", $_POST["record_sort_by"])) {
    define('RECORD_SORT_BY', $_POST["record_sort_by"]);
    $_SESSION["record_sort_by"] = $_POST["record_sort_by"];
} elseif (isset($_SESSION["record_sort_by"])) {
    define('RECORD_SORT_BY', $_SESSION["record_sort_by"]);
} else {
    /** Record to sort zone by
     */
    define('RECORD_SORT_BY', "name");
}

// Updated on 2022-01-02 - 1488 TLDs
// http://data.iana.org/TLD/tlds-alpha-by-domain.txt
// for w in `cat tlds-alpha-by-domain.txt`; do echo -n "\"$w\", " | tr '[:upper:]' '[:lower:]'; done | fold -s -w 79 | sed -e 's/^/  /g'
$valid_tlds = array(  "aaa", "aarp", "abarth", "abb", "abbott", "abbvie", "abc", "able", "abogado",
    "abudhabi", "ac", "academy", "accenture", "accountant", "accountants", "aco",
    "actor", "ad", "adac", "ads", "adult", "ae", "aeg", "aero", "aetna", "af",
    "afl", "africa", "ag", "agakhan", "agency", "ai", "aig", "airbus", "airforce",
    "airtel", "akdn", "al", "alfaromeo", "alibaba", "alipay", "allfinanz",
    "allstate", "ally", "alsace", "alstom", "am", "amazon", "americanexpress",
    "americanfamily", "amex", "amfam", "amica", "amsterdam", "analytics",
    "android", "anquan", "anz", "ao", "aol", "apartments", "app", "apple", "aq",
    "aquarelle", "ar", "arab", "aramco", "archi", "army", "arpa", "art", "arte",
    "as", "asda", "asia", "associates", "at", "athleta", "attorney", "au",
    "auction", "audi", "audible", "audio", "auspost", "author", "auto", "autos",
    "avianca", "aw", "aws", "ax", "axa", "az", "azure", "ba", "baby", "baidu",
    "banamex", "bananarepublic", "band", "bank", "bar", "barcelona",
    "barclaycard", "barclays", "barefoot", "bargains", "baseball", "basketball",
    "bauhaus", "bayern", "bb", "bbc", "bbt", "bbva", "bcg", "bcn", "bd", "be",
    "beats", "beauty", "beer", "bentley", "berlin", "best", "bestbuy", "bet",
    "bf", "bg", "bh", "bharti", "bi", "bible", "bid", "bike", "bing", "bingo",
    "bio", "biz", "bj", "black", "blackfriday", "blockbuster", "blog",
    "bloomberg", "blue", "bm", "bms", "bmw", "bn", "bnpparibas", "bo", "boats",
    "boehringer", "bofa", "bom", "bond", "boo", "book", "booking", "bosch",
    "bostik", "boston", "bot", "boutique", "box", "br", "bradesco", "bridgestone",
    "broadway", "broker", "brother", "brussels", "bs", "bt", "budapest",
    "bugatti", "build", "builders", "business", "buy", "buzz", "bv", "bw", "by",
    "bz", "bzh", "ca", "cab", "cafe", "cal", "call", "calvinklein", "cam",
    "camera", "camp", "cancerresearch", "canon", "capetown", "capital",
    "capitalone", "car", "caravan", "cards", "care", "career", "careers", "cars",
    "casa", "case", "cash", "casino", "cat", "catering", "catholic", "cba", "cbn",
    "cbre", "cbs", "cc", "cd", "center", "ceo", "cern", "cf", "cfa", "cfd", "cg",
    "ch", "chanel", "channel", "charity", "chase", "chat", "cheap", "chintai",
    "christmas", "chrome", "church", "ci", "cipriani", "circle", "cisco",
    "citadel", "citi", "citic", "city", "cityeats", "ck", "cl", "claims",
    "cleaning", "click", "clinic", "clinique", "clothing", "cloud", "club",
    "clubmed", "cm", "cn", "co", "coach", "codes", "coffee", "college", "cologne",
    "com", "comcast", "commbank", "community", "company", "compare", "computer",
    "comsec", "condos", "construction", "consulting", "contact", "contractors",
    "cooking", "cookingchannel", "cool", "coop", "corsica", "country", "coupon",
    "coupons", "courses", "cpa", "cr", "credit", "creditcard", "creditunion",
    "cricket", "crown", "crs", "cruise", "cruises", "csc", "cu", "cuisinella",
    "cv", "cw", "cx", "cy", "cymru", "cyou", "cz", "dabur", "dad", "dance",
    "data", "date", "dating", "datsun", "day", "dclk", "dds", "de", "deal",
    "dealer", "deals", "degree", "delivery", "dell", "deloitte", "delta",
    "democrat", "dental", "dentist", "desi", "design", "dev", "dhl", "diamonds",
    "diet", "digital", "direct", "directory", "discount", "discover", "dish",
    "diy", "dj", "dk", "dm", "dnp", "do", "docs", "doctor", "dog", "domains",
    "dot", "download", "drive", "dtv", "dubai", "dunlop", "dupont", "durban",
    "dvag", "dvr", "dz", "earth", "eat", "ec", "eco", "edeka", "edu", "education",
    "ee", "eg", "email", "emerck", "energy", "engineer", "engineering",
    "enterprises", "epson", "equipment", "er", "ericsson", "erni", "es", "esq",
    "estate", "et", "etisalat", "eu", "eurovision", "eus", "events", "exchange",
    "expert", "exposed", "express", "extraspace", "fage", "fail", "fairwinds",
    "faith", "family", "fan", "fans", "farm", "farmers", "fashion", "fast",
    "fedex", "feedback", "ferrari", "ferrero", "fi", "fiat", "fidelity", "fido",
    "film", "final", "finance", "financial", "fire", "firestone", "firmdale",
    "fish", "fishing", "fit", "fitness", "fj", "fk", "flickr", "flights", "flir",
    "florist", "flowers", "fly", "fm", "fo", "foo", "food", "foodnetwork",
    "football", "ford", "forex", "forsale", "forum", "foundation", "fox", "fr",
    "free", "fresenius", "frl", "frogans", "frontdoor", "frontier", "ftr",
    "fujitsu", "fun", "fund", "furniture", "futbol", "fyi", "ga", "gal",
    "gallery", "gallo", "gallup", "game", "games", "gap", "garden", "gay", "gb",
    "gbiz", "gd", "gdn", "ge", "gea", "gent", "genting", "george", "gf", "gg",
    "ggee", "gh", "gi", "gift", "gifts", "gives", "giving", "gl", "glass", "gle",
    "global", "globo", "gm", "gmail", "gmbh", "gmo", "gmx", "gn", "godaddy",
    "gold", "goldpoint", "golf", "goo", "goodyear", "goog", "google", "gop",
    "got", "gov", "gp", "gq", "gr", "grainger", "graphics", "gratis", "green",
    "gripe", "grocery", "group", "gs", "gt", "gu", "guardian", "gucci", "guge",
    "guide", "guitars", "guru", "gw", "gy", "hair", "hamburg", "hangout", "haus",
    "hbo", "hdfc", "hdfcbank", "health", "healthcare", "help", "helsinki", "here",
    "hermes", "hgtv", "hiphop", "hisamitsu", "hitachi", "hiv", "hk", "hkt", "hm",
    "hn", "hockey", "holdings", "holiday", "homedepot", "homegoods", "homes",
    "homesense", "honda", "horse", "hospital", "host", "hosting", "hot",
    "hoteles", "hotels", "hotmail", "house", "how", "hr", "hsbc", "ht", "hu",
    "hughes", "hyatt", "hyundai", "ibm", "icbc", "ice", "icu", "id", "ie", "ieee",
    "ifm", "ikano", "il", "im", "imamat", "imdb", "immo", "immobilien", "in",
    "inc", "industries", "infiniti", "info", "ing", "ink", "institute",
    "insurance", "insure", "int", "international", "intuit", "investments", "io",
    "ipiranga", "iq", "ir", "irish", "is", "ismaili", "ist", "istanbul", "it",
    "itau", "itv", "jaguar", "java", "jcb", "je", "jeep", "jetzt", "jewelry",
    "jio", "jll", "jm", "jmp", "jnj", "jo", "jobs", "joburg", "jot", "joy", "jp",
    "jpmorgan", "jprs", "juegos", "juniper", "kaufen", "kddi", "ke",
    "kerryhotels", "kerrylogistics", "kerryproperties", "kfh", "kg", "kh", "ki",
    "kia", "kim", "kinder", "kindle", "kitchen", "kiwi", "km", "kn", "koeln",
    "komatsu", "kosher", "kp", "kpmg", "kpn", "kr", "krd", "kred", "kuokgroup",
    "kw", "ky", "kyoto", "kz", "la", "lacaixa", "lamborghini", "lamer",
    "lancaster", "lancia", "land", "landrover", "lanxess", "lasalle", "lat",
    "latino", "latrobe", "law", "lawyer", "lb", "lc", "lds", "lease", "leclerc",
    "lefrak", "legal", "lego", "lexus", "lgbt", "li", "lidl", "life",
    "lifeinsurance", "lifestyle", "lighting", "like", "lilly", "limited", "limo",
    "lincoln", "linde", "link", "lipsy", "live", "living", "lk", "llc", "llp",
    "loan", "loans", "locker", "locus", "loft", "lol", "london", "lotte", "lotto",
    "love", "lpl", "lplfinancial", "lr", "ls", "lt", "ltd", "ltda", "lu",
    "lundbeck", "luxe", "luxury", "lv", "ly", "ma", "macys", "madrid", "maif",
    "maison", "makeup", "man", "management", "mango", "map", "market",
    "marketing", "markets", "marriott", "marshalls", "maserati", "mattel", "mba",
    "mc", "mckinsey", "md", "me", "med", "media", "meet", "melbourne", "meme",
    "memorial", "men", "menu", "merckmsd", "mg", "mh", "miami", "microsoft",
    "mil", "mini", "mint", "mit", "mitsubishi", "mk", "ml", "mlb", "mls", "mm",
    "mma", "mn", "mo", "mobi", "mobile", "moda", "moe", "moi", "mom", "monash",
    "money", "monster", "mormon", "mortgage", "moscow", "moto", "motorcycles",
    "mov", "movie", "mp", "mq", "mr", "ms", "msd", "mt", "mtn", "mtr", "mu",
    "museum", "music", "mutual", "mv", "mw", "mx", "my", "mz", "na", "nab",
    "nagoya", "name", "natura", "navy", "nba", "nc", "ne", "nec", "net",
    "netbank", "netflix", "network", "neustar", "new", "news", "next",
    "nextdirect", "nexus", "nf", "nfl", "ng", "ngo", "nhk", "ni", "nico", "nike",
    "nikon", "ninja", "nissan", "nissay", "nl", "no", "nokia",
    "northwesternmutual", "norton", "now", "nowruz", "nowtv", "np", "nr", "nra",
    "nrw", "ntt", "nu", "nyc", "nz", "obi", "observer", "office", "okinawa",
    "olayan", "olayangroup", "oldnavy", "ollo", "om", "omega", "one", "ong",
    "onl", "online", "ooo", "open", "oracle", "orange", "org", "organic",
    "origins", "osaka", "otsuka", "ott", "ovh", "pa", "page", "panasonic",
    "paris", "pars", "partners", "parts", "party", "passagens", "pay", "pccw",
    "pe", "pet", "pf", "pfizer", "pg", "ph", "pharmacy", "phd", "philips",
    "phone", "photo", "photography", "photos", "physio", "pics", "pictet",
    "pictures", "pid", "pin", "ping", "pink", "pioneer", "pizza", "pk", "pl",
    "place", "play", "playstation", "plumbing", "plus", "pm", "pn", "pnc", "pohl",
    "poker", "politie", "porn", "post", "pr", "pramerica", "praxi", "press",
    "prime", "pro", "prod", "productions", "prof", "progressive", "promo",
    "properties", "property", "protection", "pru", "prudential", "ps", "pt",
    "pub", "pw", "pwc", "py", "qa", "qpon", "quebec", "quest", "racing", "radio",
    "re", "read", "realestate", "realtor", "realty", "recipes", "red", "redstone",
    "redumbrella", "rehab", "reise", "reisen", "reit", "reliance", "ren", "rent",
    "rentals", "repair", "report", "republican", "rest", "restaurant", "review",
    "reviews", "rexroth", "rich", "richardli", "ricoh", "ril", "rio", "rip", "ro",
    "rocher", "rocks", "rodeo", "rogers", "room", "rs", "rsvp", "ru", "rugby",
    "ruhr", "run", "rw", "rwe", "ryukyu", "sa", "saarland", "safe", "safety",
    "sakura", "sale", "salon", "samsclub", "samsung", "sandvik",
    "sandvikcoromant", "sanofi", "sap", "sarl", "sas", "save", "saxo", "sb",
    "sbi", "sbs", "sc", "sca", "scb", "schaeffler", "schmidt", "scholarships",
    "school", "schule", "schwarz", "science", "scot", "sd", "se", "search",
    "seat", "secure", "security", "seek", "select", "sener", "services", "ses",
    "seven", "sew", "sex", "sexy", "sfr", "sg", "sh", "shangrila", "sharp",
    "shaw", "shell", "shia", "shiksha", "shoes", "shop", "shopping", "shouji",
    "show", "showtime", "si", "silk", "sina", "singles", "site", "sj", "sk",
    "ski", "skin", "sky", "skype", "sl", "sling", "sm", "smart", "smile", "sn",
    "sncf", "so", "soccer", "social", "softbank", "software", "sohu", "solar",
    "solutions", "song", "sony", "soy", "spa", "space", "sport", "spot", "sr",
    "srl", "ss", "st", "stada", "staples", "star", "statebank", "statefarm",
    "stc", "stcgroup", "stockholm", "storage", "store", "stream", "studio",
    "study", "style", "su", "sucks", "supplies", "supply", "support", "surf",
    "surgery", "suzuki", "sv", "swatch", "swiss", "sx", "sy", "sydney", "systems",
    "sz", "tab", "taipei", "talk", "taobao", "target", "tatamotors", "tatar",
    "tattoo", "tax", "taxi", "tc", "tci", "td", "tdk", "team", "tech",
    "technology", "tel", "temasek", "tennis", "teva", "tf", "tg", "th", "thd",
    "theater", "theatre", "tiaa", "tickets", "tienda", "tiffany", "tips", "tires",
    "tirol", "tj", "tjmaxx", "tjx", "tk", "tkmaxx", "tl", "tm", "tmall", "tn",
    "to", "today", "tokyo", "tools", "top", "toray", "toshiba", "total", "tours",
    "town", "toyota", "toys", "tr", "trade", "trading", "training", "travel",
    "travelchannel", "travelers", "travelersinsurance", "trust", "trv", "tt",
    "tube", "tui", "tunes", "tushu", "tv", "tvs", "tw", "tz", "ua", "ubank",
    "ubs", "ug", "uk", "unicom", "university", "uno", "uol", "ups", "us", "uy",
    "uz", "va", "vacations", "vana", "vanguard", "vc", "ve", "vegas", "ventures",
    "verisign", "versicherung", "vet", "vg", "vi", "viajes", "video", "vig",
    "viking", "villas", "vin", "vip", "virgin", "visa", "vision", "viva", "vivo",
    "vlaanderen", "vn", "vodka", "volkswagen", "volvo", "vote", "voting", "voto",
    "voyage", "vu", "vuelos", "wales", "walmart", "walter", "wang", "wanggou",
    "watch", "watches", "weather", "weatherchannel", "webcam", "weber", "website",
    "wed", "wedding", "weibo", "weir", "wf", "whoswho", "wien", "wiki",
    "williamhill", "win", "windows", "wine", "winners", "wme", "wolterskluwer",
    "woodside", "work", "works", "world", "wow", "ws", "wtc", "wtf", "xbox",
    "xerox", "xfinity", "xihuan", "xin", "xn--11b4c3d", "xn--1ck2e1b",
    "xn--1qqw23a", "xn--2scrj9c", "xn--30rr7y", "xn--3bst00m", "xn--3ds443g",
    "xn--3e0b707e", "xn--3hcrj9c", "xn--3pxu8k", "xn--42c2d9a", "xn--45br5cyl",
    "xn--45brj9c", "xn--45q11c", "xn--4dbrk0ce", "xn--4gbrim", "xn--54b7fta0cc",
    "xn--55qw42g", "xn--55qx5d", "xn--5su34j936bgsg", "xn--5tzm5g", "xn--6frz82g",
    "xn--6qq986b3xl", "xn--80adxhks", "xn--80ao21a", "xn--80aqecdr1a",
    "xn--80asehdb", "xn--80aswg", "xn--8y0a063a", "xn--90a3ac", "xn--90ae",
    "xn--90ais", "xn--9dbq2a", "xn--9et52u", "xn--9krt00a", "xn--b4w605ferd",
    "xn--bck1b9a5dre4c", "xn--c1avg", "xn--c2br7g", "xn--cck2b3b",
    "xn--cckwcxetd", "xn--cg4bki", "xn--clchc0ea0b2g2a9gcd", "xn--czr694b",
    "xn--czrs0t", "xn--czru2d", "xn--d1acj3b", "xn--d1alf", "xn--e1a4c",
    "xn--eckvdtc9d", "xn--efvy88h", "xn--fct429k", "xn--fhbei", "xn--fiq228c5hs",
    "xn--fiq64b", "xn--fiqs8s", "xn--fiqz9s", "xn--fjq720a", "xn--flw351e",
    "xn--fpcrj9c3d", "xn--fzc2c9e2c", "xn--fzys8d69uvgm", "xn--g2xx48c",
    "xn--gckr3f0f", "xn--gecrj9c", "xn--gk3at1e", "xn--h2breg3eve", "xn--h2brj9c",
    "xn--h2brj9c8c", "xn--hxt814e", "xn--i1b6b1a6a2e", "xn--imr513n",
    "xn--io0a7i", "xn--j1aef", "xn--j1amh", "xn--j6w193g", "xn--jlq480n2rg",
    "xn--jlq61u9w7b", "xn--jvr189m", "xn--kcrx77d1x4a", "xn--kprw13d",
    "xn--kpry57d", "xn--kput3i", "xn--l1acc", "xn--lgbbat1ad8j", "xn--mgb9awbf",
    "xn--mgba3a3ejt", "xn--mgba3a4f16a", "xn--mgba7c0bbn0a", "xn--mgbaakc7dvf",
    "xn--mgbaam7a8h", "xn--mgbab2bd", "xn--mgbah1a3hjkrd", "xn--mgbai9azgqp6j",
    "xn--mgbayh7gpa", "xn--mgbbh1a", "xn--mgbbh1a71e", "xn--mgbc0a9azcg",
    "xn--mgbca7dzdo", "xn--mgbcpq6gpa1a", "xn--mgberp4a5d4ar", "xn--mgbgu82a",
    "xn--mgbi4ecexp", "xn--mgbpl2fh", "xn--mgbt3dhd", "xn--mgbtx2b",
    "xn--mgbx4cd0ab", "xn--mix891f", "xn--mk1bu44c", "xn--mxtq1m", "xn--ngbc5azd",
    "xn--ngbe9e0a", "xn--ngbrx", "xn--node", "xn--nqv7f", "xn--nqv7fs00ema",
    "xn--nyqy26a", "xn--o3cw4h", "xn--ogbpf8fl", "xn--otu796d", "xn--p1acf",
    "xn--p1ai", "xn--pgbs0dh", "xn--pssy2u", "xn--q7ce6a", "xn--q9jyb4c",
    "xn--qcka1pmc", "xn--qxa6a", "xn--qxam", "xn--rhqv96g", "xn--rovu88b",
    "xn--rvc1e0am3e", "xn--s9brj9c", "xn--ses554g", "xn--t60b56a", "xn--tckwe",
    "xn--tiq49xqyj", "xn--unup4y", "xn--vermgensberater-ctb",
    "xn--vermgensberatung-pwb", "xn--vhquv", "xn--vuq861b",
    "xn--w4r85el8fhu5dnra", "xn--w4rs40l", "xn--wgbh1c", "xn--wgbl6a",
    "xn--xhq521b", "xn--xkc2al3hye2a", "xn--xkc2dl3a5ee0h", "xn--y9a3aq",
    "xn--yfro4i67o", "xn--ygbi2ammx", "xn--zfr164b", "xxx", "xyz", "yachts",
    "yahoo", "yamaxun", "yandex", "ye", "yodobashi", "yoga", "yokohama", "you",
    "youtube", "yt", "yun", "za", "zappos", "zara", "zero", "zip", "zm", "zone",
    "zuerich", "zw");

// Special TLDs for testing and documentation purposes
// http://tools.ietf.org/html/rfc2606#section-2
array_push($valid_tlds, 'test', 'example', 'invalid', 'localhost');

/* Database connection */
require_once("database.inc.php");
// Generates $db variable to access database.
// Array of the available zone types
$server_types = array("MASTER", "SLAVE", "NATIVE");

// The following is a list of supported record types by PowerDNS
// https://doc.powerdns.com/authoritative/appendices/types.html

// $rtypes - array of possible record types
$rtypes = array(
    'A',
    'A6',
    'AAAA',
    'AFSDB',
    'ALIAS',
    'APL',
    'CAA',
    'CDNSKEY',
    'CDS',
    'CERT',
    'CNAME',
    'CSYNC',
    'DHCID',
    'DLV',
    'DNAME',
    'DNSKEY',
    'DS',
    'EUI48',
    'EUI64',
    'HINFO',
    'HTTPS',
    'IPSECKEY',
    'KEY',
    'KX',
    'L32',
    'L64',
    'LOC',
    'LP',
    'MAILA',
    'MAILB',
    'MINFO',
    'MR',
    'MX',
    'NAPTR',
    'NID',
    'NS',
    'NSEC',
    'NSEC3',
    'NSEC3PARAM',
    'OPENPGPKEY',
    'PTR',
    'RKEY',
    'RP',
    'RRSIG',
    'SIG',
    'SMIMEA',
    'SOA',
    'SPF',
    'SRV',
    'SSHFP',
    'SVCB',
    'TKEY',
    'TLSA',
    'TSIG',
    'TXT',
    'URI',
    'WKS'
);

// If fancy records is enabled, extend this field.
if ($dns_fancy) {
    $rtypes[] = 'URL';
    $rtypes[] = 'MBOXFW';
    $rtypes[] = 'CURL';
}


/* * ***********
 * Includes  *
 * *********** */
$db = dbConnect();
require_once "plugin.inc.php";
require_once "i18n.inc.php";
require_once "auth.inc.php";
require_once "users.inc.php";
require_once "dns.inc.php";
require_once "record.inc.php";
require_once "dnssec.inc.php";
require_once "templates.inc.php";

//do_hook('hook_post_includes');
do_hook('authenticate');


/* * ***********
 * Functions *
 * *********** */

/** Print paging menu
 *
 * Display the page option: [ < ][ 1 ] .. [ 8 ][ 9 ][ 10 ][ 11 ][ 12 ][ 13 ][ 14 ][ 15 ][ 16 ] .. [ 34 ][ > ]
 *
 * @param int $amount Total number of items
 * @param int $rowamount Per page number of items
 * @param int $id Page specific ID (Zone ID, Template ID, etc)
 *
 * @return null
 */
function show_pages($amount, $rowamount, $id = '') {
    if ($amount > $rowamount) {
        $num = 8;
        $poutput = '';
        $lastpage = ceil($amount / $rowamount);
        $startpage = 1;

        if (!isset($_GET["start"]))
            $_GET["start"] = 1;
        $start = $_GET["start"];

        if ($lastpage > $num & $start > ($num / 2)) {
            $startpage = ($start - ($num / 2));
        }

        echo _('Show page') . ":<br>";

        if ($lastpage > $num & $start > 1) {
            $poutput .= '<a href=" ' . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES);
            $poutput .= '?start=' . ($start - 1);
            if ($id != '')
                $poutput .= '&id=' . $id;
            $poutput .= '">';
            $poutput .= '[ < ]';
            $poutput .= '</a>';
        }
        if ($start != 1) {
            $poutput .= '<a href=" ' . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES);
            $poutput .= '?start=1';
            if ($id != '')
                $poutput .= '&id=' . $id;
            $poutput .= '">';
            $poutput .= '[ 1 ]';
            $poutput .= '</a>';
            if ($startpage > 2)
                $poutput .= ' .. ';
        }

        for ($i = $startpage; $i <= min(($startpage + $num), $lastpage); $i++) {
            if ($start == $i) {
                $poutput .= '[ <b>' . $i . '</b> ]';
            } elseif ($i != $lastpage & $i != 1) {
                $poutput .= '<a href=" ' . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES);
                $poutput .= '?start=' . $i;
                if ($id != '')
                    $poutput .= '&id=' . $id;
                $poutput .= '">';
                $poutput .= '[ ' . $i . ' ]';
                $poutput .= '</a>';
            }
        }

        if ($start != $lastpage) {
            if (min(($startpage + $num), $lastpage) < ($lastpage - 1))
                $poutput .= ' .. ';
            $poutput .= '<a href=" ' . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES);
            $poutput .= '?start=' . $lastpage;
            if ($id != '')
                $poutput .= '&id=' . $id;
            $poutput .= '">';
            $poutput .= '[ ' . $lastpage . ' ]';
            $poutput .= '</a>';
        }

        if ($lastpage > $num & $start < $lastpage) {
            $poutput .= '<a href=" ' . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES);
            $poutput .= '?start=' . ($start + 1);
            if ($id != '')
                $poutput .= '&id=' . $id;
            $poutput .= '">';
            $poutput .= '[ > ]';
            $poutput .= '</a>';
        }

        echo $poutput;
    }
}

/** Print alphanumeric paging menu
 *
 * Display the alphabetic option: [0-9] [a] [b] .. [z]
 *
 * @param string $letterstart Starting letter/number or 'all'
 * @param int $userid Current user ID
 *
 * @return null
 */
function show_letters($letterstart, $userid) {
    global $db;

    $char_range = array_merge(range('a', 'z'), array('_'));

    $allowed = zone_content_view_others($userid);

    $query = "SELECT
			DISTINCT ".dbfunc_substr()."(domains.name, 1, 1) AS letter
			FROM domains
			LEFT JOIN zones ON domains.id = zones.domain_id
			WHERE " . $allowed . " = 1
			OR zones.owner = " . $userid . "
			ORDER BY 1";
    $db->setLimit(36);

    $available_chars = array();
    $digits_available = 0;

    $response = $db->query($query);

    while ($row = $response->fetchRow()) {
        if (preg_match("/[0-9]/", $row['letter'])) {
            $digits_available = 1;
        } elseif (in_array($row['letter'], $char_range)) {
            array_push($available_chars, $row['letter']);
        }
    }

    echo _('Show zones beginning with') . ":<br>";

    if ($letterstart == "1") {
        echo "<span class=\"lettertaken\">[ 0-9 ]</span> ";
    } elseif ($digits_available) {
        echo "<a href=\"" . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES) . "?letter=1\">[ 0-9 ]</a> ";
    } else {
        echo "[ <span class=\"letternotavailable\">0-9</span> ] ";
    }

    foreach ($char_range as $letter) {
        if ($letter == $letterstart) {
            echo "<span class=\"lettertaken\">[ " . $letter . " ]</span> ";
        } elseif (in_array($letter, $available_chars)) {
            echo "<a href=\"" . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES) . "?letter=" . $letter . "\">[ " . $letter . " ]</a> ";
        } else {
            echo "[ <span class=\"letternotavailable\">" . $letter . "</span> ] ";
        }
    }

    if ($letterstart == 'all') {
        echo "<span class=\"lettertaken\">[ Show all ]</span>";
    } else {
        echo "<a href=\"" . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES) . "?letter=all\">[ Show all ]</a> ";
    }
}

/** Check if current user allowed to view any zone content
 *
 * @param int $userid Current user ID
 *
 * @return int 1 if user has permission to view other users zones content, 0 otherwise
 */
function zone_content_view_others($userid) {
    global $db;

    $query = "SELECT
		DISTINCT u.id
		FROM 	users u,
		        perm_templ pt,
		        perm_templ_items pti,
		        (SELECT id FROM perm_items WHERE name
			    IN ('zone_content_view_others', 'user_is_ueberuser')) pit
                WHERE u.id = " . $userid . "
                AND u.perm_templ = pt.id
                AND pti.templ_id = pt.id
                AND pti.perm_id  = pit.id";

    $result = $db->queryOne($query);

    return ($result ? 1 : 0);
}

/** Print success message (toolkit.inc)
 *
 * @param string $msg Success message
 *
 * @return null
 */
function success($msg) {
    if ($msg) {
        echo "     <div class=\"success\">" . $msg . "</div>\n";
    } else {
        echo "     <div class=\"success\">" . _('Something has been successfully performed. What exactly, however, will remain a mystery.') . "</div>\n";
    }
}

/** Print message
 *
 * Something has been done nicely, display a message and a back button.
 *
 * @param string $msg Message
 *
 * @return null
 */
function message($msg) {
    include_once("header.inc.php");
    ?>
    <P><TABLE CLASS="messagetable"><TR><TD CLASS="message"><H2><?php echo _('Success!'); ?></H2>
                <BR>
                <FONT STYLE="font-weight: Bold">
                <P>
                    <?php
                    if ($msg) {
                        echo nl2br($msg);
                    } else {
                        echo _('Successful!');
                    }
                    ?>
                </P>
                <BR>
                <P>
                    <a href="javascript:history.go(-1)">&lt;&lt; <?php echo _('back'); ?></a></FONT>
                </P>
            </TD></TR></TABLE></P>
    <?php
    include_once("footer.inc.php");
}

/** Send 302 Redirect with optional argument
 *
 * Reroute a user to a cleanpage of (if passed) arg
 *
 * @param string $arg argument string to add to url
 *
 * @return null
 */
function clean_page($arg = '') {
    if (!$arg) {
        header("Location: " . htmlentities($_SERVER['SCRIPT_NAME'], ENT_QUOTES) . "?time=" . time());
        exit;
    } else {
        if (preg_match('!\?!si', $arg)) {
            $add = "&time=";
        } else {
            $add = "?time=";
        }
        header("Location: $arg$add" . time());
        exit;
    }
}

/** Validate user name string
 *
 * @param string $username user name string
 *
 * @return boolean true if valid, false otherwise
 */
function is_valid_username($username) {
    return $username != '';
}

/** Validate email address string
 *
 * @param string $address email address string
 *
 * @return boolean true if valid, false otherwise
 */
function is_valid_email($address) {
    $fields = preg_split("/@/", $address, 2);
    if ((!preg_match("/^[0-9a-z]([-_.]?[0-9a-z])*$/i", $fields[0])) || (!isset($fields[1]) || $fields[1] == '' || !is_valid_hostname_fqdn($fields[1], 0))) {
        return false;
    }
    return true;
}

/** Validate numeric string
 *
 * @param string $string number
 *
 * @return boolean true if number, false otherwise
 */
function v_num($string) {
    if (!preg_match("/^[0-9]+$/i", $string)) {
        return false;
    } else {
        return true;
    }
}

/** Debug print
 *
 * @param string $var debug statement
 *
 * @return null
 */
function debug_print($var) {
    echo "<pre style=\"border: 2px solid blue;\">\n";
    if (is_array($var)) {
        print_r($var);
    } else {
        echo $var;
    }
    echo "</pre>\n";
}

function do_log($syslog_message, $priority) {
    global $syslog_use, $syslog_ident, $syslog_facility;
    if ($syslog_use) {
        openlog($syslog_ident, LOG_PERROR, $syslog_facility);
        syslog($priority, $syslog_message);
        closelog();
    }
}

function log_error($syslog_message) {
    do_log($syslog_message, LOG_ERR);
}

function log_warn($syslog_message) {
    do_log($syslog_message, LOG_WARNING);
}

function log_notice($syslog_message) {
    do_log($syslog_message, LOG_NOTICE);
}

function log_info($syslog_message) {
    do_log($syslog_message, LOG_INFO);
}

/** Print the login form
 *
 * @param string $msg Error Message
 * @param string $type Message type [default='success', 'error']
 *
 * @return null
 */
function auth($msg = "", $type = "success") {
    include_once 'inc/header.inc.php';
    include_once 'inc/config.inc.php';
    global $iface_lang;

    if ($msg) {
        print "<div class=\"$type\">$msg</div>\n";
    }
    ?>
    <h2><?php echo _('Log in'); ?></h2>
    <form method="post" action="<?php echo htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES); ?>">
        <input type="hidden" name="query_string" value="<?php echo htmlentities($_SERVER["QUERY_STRING"]); ?>">
        <table border="0">
            <tr>
                <td class="n" width="100"><?php echo _('Username'); ?>:</td>
                <td class="n"><input type="text" class="input" name="username" id="username"></td>
            </tr>
            <tr>
                <td class="n"><?php echo _('Password'); ?>:</td>
                <td class="n"><input type="password" class="input" name="password"></td>
            </tr>
            <tr>
                <td class="n"><?php echo _('Language'); ?>:</td>
                <td class="n">
                    <select class="input" name="userlang">
                        <?php
                        // List available languages (sorted alphabetically)
                        include_once 'inc/countrycodes.inc.php';
                        $locales = scandir('locale/');
                        foreach ($locales as $locale) {
                            if (strlen($locale) == 5) {
                                $locales_fullname[$locale] = $country_codes[substr($locale, 0, 2)];
                            }
                        }
                        asort($locales_fullname);
                        foreach ($locales_fullname as $locale => $language) {
                            if (substr($locale, 0, 2) == substr($iface_lang, 0, 2)) {
                                echo _('<option selected value="' . $locale . '">' . $language);
                            } else {
                                echo _('<option value="' . $locale . '">' . $language);
                            }
                        }
                        ?>
                    </select>
                </td>
            </tr>
            <tr>
                <td class="n">&nbsp;</td>
                <td class="n">
                    <input type="submit" name="authenticate" class="button" value=" <?php echo _('Go'); ?> ">
                </td>
            </tr>
        </table>
    </form>
    <script type="text/javascript">
        <!--
      document.getElementById('username').focus();
        //-->
    </script>
    <?php
    include_once('inc/footer.inc.php');
    exit;
}

/** Logout the user
 *
 * Logout the user and kickback to login form
 *
 * @param string $msg Error Message
 * @param string $type Message type [default='']
 *
 * @return null
 */
function logout($msg = "", $type = "") {
    session_unset();
    session_destroy();
    session_write_close();
    auth($msg, $type);
    exit;
}

/** Matches end of string
 *
 * Matches end of string (haystack) against another string (needle)
 *
 * @param string $needle
 * @param string $haystack
 *
 * @return true if ends with specified string, otherwise false
 */
function endsWith($needle, $haystack) {
    $length = strlen($haystack);
    $nLength = strlen($needle);
    return $nLength <= $length && strncmp(substr($haystack, -$nLength), $needle, $nLength) === 0;
}
