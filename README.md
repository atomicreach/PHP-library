Atomic Reach PHP Library
===========

PHP library to work with Atomic Reach API

The purpose of this document is to explain how to integrate with the Atomic Reach (AR) API.

## Authentication

AR API is based on Oauth and client's of the API will require the Oauth credentials (consumer and secret keys) to communicate with the AR API. After [creating an account][2] on Atomic Reach, the keys can be obtained in two different ways:

1. By accessing the User's [profile page][3] \- the keys are displayed and can be copied into your client code where API calls are made, OR
2. By logging in from the API client and providing a callback URL. To do this, just open a new popup window with the following URL:

        `https://score.atomicreach.com/account/remote-login?callback=YOUR_CALLBACK_URL`


where `YOUR_CALLBACK_URL` = a URL on the client-side for the API to respond to. This will provide a login window where the User can provide their account credentials to authenticate with the API. The API will return both keys to your callback URL via GET parameters: `key` and `secret`. It is recommended that you safely store these keys in your application for further calls to the API and to avoid a User having to login every time.

## Service Request

All service requests must be sent to:


    `https://api.score.atomicreach.com`


Arguments are sent via POST parameters.

To simplify AR API integration, you may choose to use an AR API Client that you can download from our developer page.

See the sections below for more details on what services and methods are available and which arguments each service and method requires.

## Service Response

All responses are UTF-8 encoded. Each response has an envelope that contain the following parts:

* `status` (int, mandatory): possible values are
`
10: OK
20: INTERNAL ERROR
21: INVALID ACCESS TOKEN
22: THRESHOLD EXCEEDED
23: INVALID ACTION
24: INVALID DATA
`
* `error` (string, optional): in the event of a non-`OK` `status`, this will contain a description of the error.

Other parts contained in the response will depend on the service call made.

### Response Format

The response will be returned in JSON format (Content-Type: `text/json`).

## Available Services

Here are all currently available services that can be called by using our AR API Client.

### `post/add`

Adds a new post into the AR system.

Parameters

* `text` (string): body of the post.
* `teaser` (string): introductory text of the post.
* `sourceId` (int): content source ID.
* `segmentId` (int): audience segment ID. This parameter is optional..
* `title` (string): title of the post.
* `pubDate` (string): publication date of the post. Must be in format `yyyy-mm-dd`.
* `url` (string): url of the post.

### `post/analyze`

Analyzes a given post, typically in a pre-publishing use, and responds with the results of the analysis. This operation does not save the post or the analysis results.

Parameters

* `content` (string): the text to be analyzed.
* `title` (string): the title to be analyzed. This parameter is optional.
* `segmentId` (int): the post audience segment. This parameter is optional.

Normal Response



    data (Object)
    (
         analysis (Object): object that contains the analysis results
         (
              lc (Object): link count
              (
                   state (string): red, yellow, green
                   valid (int): valid link count
                   invalid (int): invalid link count
                   total (int): total link count
                   detail (Array[string]): invalid link list
              )
              sm (Object): spelling mistakes
              (
                   state (string): red, yellow, green
                   total (int): total spelling mistakes count
                   detail (Array[Object]): spelling mistakes word list
                   (
                        string (string): the actual word with spelling mistakes
                        precontext (string): the previous word
                        description (string): the spelling type
                        suggestions (Object)
                        (
                             option (Array[string]): suggestion word list
                        )
                       url (string): link to an explanation of the spelling mistake (this URL may not be provided if the explanation isn't available)
                   )
              )
              gm (Object): grammar mistakes
                   state (string): red, yellow, green
                   total (int): total grammar mistakes count
                   detail (Array[Object]): grammar mistakes word list
                   (
                        string (string): the actual word with grammar mistakes
                        precontext (string): the previous word
                        description (string): the grammar type
                        suggestions (Object)
                        (
                             option (Array[string]): suggestion word list
                        )
                       url (string): link to an explanation of the grammar mistake (this URL may not be provided if the explanation isn't available)
                   )
              )
              tg (Object): tags included on the text
              (
                    state (string): red, yellow, green
                    total (int): tag count
                    detail (Array[string]): the tag list
              )
              so (Object): sophistication
              (
                    state (string): red, yellow, green
                    detail (string): the audience match of the text (TOO SIMPLE/HIT/TOO COMPLEX)
                    message (string): a message explaining the results
                    paragraphs (array): the audience match for each paragraph
                    paragraphDOM (string) : contains the delimiters that a paragraph could have. For instance, @p@, @li@ or @dd@. It is to highlight audience match paragraphs levels in the post.
                    paragraphTeasers (array): the teaser text for each paragraph.
                    paragraphDetails ( Array[Object]): details of the sophistication match, only relevant mismatches are returned {
                        index (string): index of this paragraph within all paragraphs in the text (base 0)
                        teaser (string): the teaser text for the paragraph
                        matchResult (string): the sophistication match for the paragraph (TOO SIMPLE/TOO COMPLEX)

              )
              ln (Object): sentence length
              (
                    state (string): red, yellow, green
                    measured (Object)
                    (
                         sentences (int): sentences measured count
                    )
                    recommended (Object)
                    (
                         sentences (int): recommended sentence's length (minimum)
                         sentencesMin (int): recommended minimum sentence's length
                         sentencesMax (int): recommended maximum sentence's length
                    )
              )
              su (Object): level of uniqueness of the content
              (
                    state (string): red, yellow, green
                    percentage (float): level of uniqueness, expressed as a percentage (higher = better)
                    detail (string): a message explaining the results of the analysis
                    similar (Array[Object]): similar posts list
                    (
                        title (string): the title of the post
                        url (string): the URL of the post
                    )
              )
              em (Object): level of emotion of the content
              (
                    state (string): red, yellow, green
                    detail (string): a message explaining the results of the analysis
                    dimensions (Array[Object]): emotion dimensions list
                    (
                          name (string): name of the dimension (polarity, force, or impact)
                          state (string): red, yellow, green
                          detail (string): a message explaining the recommendation associated with this dimension
                    )
              )
              lr (Object): level of repetition of the content
              (
                    state (string): red, yellow, green
                    level (int): level of repetition value
                    detail (string): a message explaining the results of the analysis
              )
              tm (Object): analysis of title
              (
                    state (string): red, yellow, green
                    detail (int): number of title criteria met
                    recomendations (array): recomendations to improve the result of this analysis
                    message (string): Message explaning the reason for current state
              )
         )
    )


No Content Source Response

When a user hasn't added a Content Source yet, or we don't have an Audience Profile for the user, the system will return the following for Audience Match:


    [so] => stdClass Object
    (
        [state] => red
        [detail] => UNAVAILABLE
        [message] => You don't have a target audience defined. Unable to determine your audience's sophistication level.
    )


The values for paragraphs, paragraphDOM and paragraphTeasers will not be returned.

### `source/add`

Adds a new content source into the AR system. This is necessary for reference in `post/add` calls.

Parameters

* `title` (string): content source title.
* `segmentDataJson` (string): JSON string containing audience segments. For Example:

        [
         {
              "segmentName":"My Easy Segment",
              "contentType":"Informal",
              "audienceType":"General",
              "primary":1
         },
         {
              "segmentName":"My Academic Segment",
              "contentType":"Informative",
              "audienceType":"Academic",
              "primary":0
         }
    ]


Available options for `contentType` are: Informal, Informative, Educational, Technical

Available options for `audienceType` are: General, Knowledgeable, Specialist, Academic

Response

* `sourceId` (int): content source ID.

### `source/get-audience-list`

Returns a list of the user content sources and their audience segments. This is necessary for reference in `post/add` and `post/analyze` calls.

Response



    sources (Array[Object])
    (
         (Object)
         (
              id (int): source id
              name (string): source name
              segments (Array[Object])
              (
                   (Object)
                   (
                        id (int): segment id
                        name (string): segment name
                        style (string): possible values: INFORMAL, INFORMATIVE, EDUCATIONAL, TECHNICAL
                        targetAudience (string): possible values: GENERAL, KNOWLEDGEABLE, SPECIALIST, ACADEMIC
                        isPrimary (int): possible values: 0, 1
                   )
              )
         )
    )


### `dictionary/add`

Adds a new word into the user's custom dictionary.

Parameters

* `word` (string): custom word to add.

### `dictionary/remove`

Removes the word from the user's custom dictionary.

Parameters

* `word` (string): custom word to remove.

### `dictionary/list`

Gets all the words from the user's custom dictionary.

Response



    words (Array[string])


## PHP API Client

`class AR_Client`
Provides Access to the AR API.

### Public methods

`function __construct(string $apiHost, string $key, string $secret)`
Constructor.

Parameters

* `apiHost`: URL to the AR API web service. For production environment must be set to: `https://api.score.atomicreach.com`
* `key`: Oauth consumer key.
* `secret`: Oauth consumer secret.

`function init()`
Initializes the connection and makes the Oauth handshake in order to establish a proper communication with the server. You should always call this method only once before making calls to the server.

`function addPost(string $text, string $teaser, int $sourceId, int $segmentId, string $title, string $pubDate, string $postUrl)`
Calls the `post/add` service.

`function analyzePost(string $content, $title = '', $segmentId = null)`
Calls the `post/analyze` service.

`function addSource(string $title, string $segmentDataJson)`
Calls the `source/add` service.

`function getAudienceList()`
Calls the `source/get-audience-list` service.

`function addDictionary(string $word)`
Calls the `dictionary/add` service.

`function removeDictionary(string $word)`
Calls the `dictionary/remove` service.

`function listDictionaries()`
Calls the `dictionary/list` service.

### Public constants

`const STATUS_OK = 10;`
`const STATUS_INTERNAL_ERROR = 20;`
`const STATUS_INVALID_ACCESS_TOKEN = 21;`
`const STATUS_THRESHOLD_EXCEEDED = 22;`
`const STATUS_INVALID_ACTION = 23;`
`const STATUS_INVALID_DATA = 24;`

### Examples


    $apiClient = new AR_Client("https://api.score.atomicreach.com", "f581a04067f5124109a0144009956ef3", "259d4d3ea6bac2170bfc4684552bd310");
    // Initialize connection
    $apiClient->init();
    // Add a source
    $segmentData = json_encode(array(array('segmentName' => 'General audience', 'contentType' => 'Informal', 'audienceType' => 'General', 'primary' => true)));
    $response = $apiClient->addSource("Test Source", $segmentData);
    // If OK, add a post for this source
    if ($response->status == AR_Client::STATUS_OK) {
        $sourceId = $response->sourceId;
        // Get audience data
        $response = $apiClient->getAudienceList();
        if ($response->status == AR_Client::STATUS_OK) {
            $segmentId = $response->sources[0]->segments[0]->id;
            $response = $apiClient->addPost("Test post body", "Test post teaser", $sourceId, $segmentId, "Test post", "2013-10-30");
            if ($response->status == AR_Client::STATUS_OK) {
                echo "Post added successfully";
            }
            else {
                echo "Error adding a post: " . $response->error;
            }
        }
        else {
            echo "Error getting audience list: " . $response->error;
        }
    }
    else {
        // Show the error
        echo "Error adding a source: " . $response->error;
    }


[2]: http://score.atomicreach.com/account/register
[3]: http://score.atomicreach.com/account/edit
