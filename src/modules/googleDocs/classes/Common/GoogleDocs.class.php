<?php
namespace Quanta\Common;



use Google_Client;
use Google_Service_Oauth2;
use Google_Service_Docs;
use Google_Service_Docs_Document;
use Google_Service_Docs_Request;
use Google_Service_Docs_BatchUpdateDocumentRequest;

/**
 * Class GoogleDocs
 */
class GoogleDocs extends \Quanta\Common\GoogleClient{

    const GENERATE_GOOGLE_DOC_PATH = "generate-google-doc";
    const GOOGLE_AUTH_CALLBACK_PATH = "google-auth-callback";

    //style types
    const GOOGLE_DOC_TITLE_STYLE = "TITLE";
    const GOOGLE_DOC_HEADING_1_STYLE = "HEADING_1";
    const GOOGLE_DOC_BULLET_DISC_CIRCLE_SQUARE_STYLE = "BULLET_DISC_CIRCLE_SQUARE";

    //TODO: remove this, this is just a test text to generate
    private $test_text = array(
        self::GOOGLE_DOC_TITLE_STYLE => "Document Title",
        self::GOOGLE_DOC_HEADING_1_STYLE => "Chapter 1: Introduction",
        self::GOOGLE_DOC_BULLET_DISC_CIRCLE_SQUARE_STYLE => "First bullet point\nSecond bullet point\nThird bullet point"

    );

    public function __construct(&$env){
       // Call the parent class constructor at the end
       parent::__construct();
       $this->service = new Google_Service_Docs($this->client);

    }

     /**
     * Create new document.
     * @param Environment $env
     */
    public function createDocument(Environment $env, $content){
        // Create a new document
        $document = new Google_Service_Docs_Document(array(
            'title' => 'Complex Formatted Document-2'
        ));

        $doc = $this->service->documents->create($document);
        $documentId = $doc->getDocumentId();
        
        $user = \Quanta\Common\UserFactory::current($env);
        //build a node in the DB with google-doc id
        \Quanta\Common\NodeFactory::buildNode($env, $doc->getDocumentId(), 'google-docs',array(
            'title' => $doc->getTitle(),
            'doc_id' => $doc->getDocumentId(),
            'author' => $user->getName()
        ));
        $this->updateDocument($env,$content,$doc);

        return $doc;
       
    }

    /**
 * Update a document.
 * @param Environment $env
 * @param Array $content
 * @param Object $document
 */
public function updateDocument(Environment $env, $content, $document) {
    $content = $this->test_text; //TODO: remove this after end testing
    // Initialize the start index
    $current_index = 1;

    $requests = [];
    foreach ($content as $type => $text_value) {
        $requests[] = new Google_Service_Docs_Request([
            'insertText' => [
                'location' => ['index' => $current_index],
                'text' => $text_value . "\n"
            ]
        ]);

        $start_index = $current_index;
        $current_index += strlen($text_value . "\n");


        switch ($type) {
            case self::GOOGLE_DOC_TITLE_STYLE:
            case self::GOOGLE_DOC_HEADING_1_STYLE:
                $requests[] = new Google_Service_Docs_Request([
                    'updateParagraphStyle' => [
                        'range' => [
                            'startIndex' => $start_index,
                            'endIndex' => $current_index
                        ],
                        'paragraphStyle' => [
                            'namedStyleType' => $type
                        ],
                        'fields' => 'namedStyleType'
                    ]
                ]);
                break;

            case self::GOOGLE_DOC_BULLET_DISC_CIRCLE_SQUARE_STYLE:
                $requests[] = new Google_Service_Docs_Request([
                    'createParagraphBullets' => [
                        'range' => [
                            'startIndex' => $start_index,
                            'endIndex' => $current_index
                        ],
                        'bulletPreset' => $type
                    ]
                ]);
                break;
        }
    }

    // Execute the batch update
    $batchUpdateRequest = new Google_Service_Docs_BatchUpdateDocumentRequest([
        'requests' => $requests
    ]);
    $this->service->documents->batchUpdate($document->getDocumentId(), $batchUpdateRequest);
}


}
