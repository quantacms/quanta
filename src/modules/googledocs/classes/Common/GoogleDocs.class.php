<?php
namespace Quanta\Common;



use Google_Client;
use Google_Service_Oauth2;
use Google_Service_Docs;
use Google_Service_Drive;
use Google_Service_Docs_Document;
use Google_Service_Docs_Request;
use Google_Service_Docs_BatchUpdateDocumentRequest;
use Google_Service_Docs_InsertTextRequest;
use Google_Service_Docs_UpdateParagraphStyleRequest;
use Google_Service_Docs_ParagraphStyle;
use DOMDocument;

/**
 * Class GoogleDocs
 */
class GoogleDocs extends \Quanta\Common\GoogleClient{

    const GENERATE_GOOGLE_DOC_PATH = "generate-google-doc";
    const READ_GOOGLE_DOC_PATH = "read-google-doc";
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
       parent::__construct($env,array(Google_Service_Docs::DOCUMENTS,Google_Service_Drive::DRIVE));
       $this->service = new Google_Service_Docs($this->client);

    }

     /**
     * Create new document.
     * @param Environment $env
     */
    public function createDocument(Environment $env, $content, $file_title = null){
        // Create a new document
        $document = new Google_Service_Docs_Document(array(
            'title' => $file_title ? $file_title : 'Generated Document'
        ));
        $doc = $this->service->documents->create($document);
        $documentId = $doc->getDocumentId();
        $this->updateDocument($documentId,$content);
        return $doc;   
    }

     /**
     * Update a document.
     * @param Environment $env
     * @param Array $content
     * @param Object $document
     */
   
     public function updateDocument($documentId, $content) {
        $current_index = 1;
        $requests = [];
        
     
        // Ensure UTF-8 encoding
        header('Content-Type: text/html; charset=utf-8');
       
        // Decode HTML content
        $content = htmlspecialchars_decode($content, ENT_QUOTES);
        $content = str_replace('<\/', '</', $content);
      
        // Parse HTML content and convert to Google Docs requests
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8">' . $content);
        $body = $dom->getElementsByTagName('body')->item(0);
        $counter =0;

        $this->parseHtmlNode($body, $requests, $current_index);

        // Execute the batch update
        $batchUpdateRequest = new Google_Service_Docs_BatchUpdateDocumentRequest([
            'requests' => $requests
        ]);
        $this->service->documents->batchUpdate($documentId, $batchUpdateRequest);
    }
    
    private function addTextNode($text,$current_index){
         // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

         // Ensure the text is UTF-8 encoded
        $text = mb_convert_encoding($text, 'UTF-8');
       
        $request = new Google_Service_Docs_Request([
            'insertText' => [
                'location' => ['index' => $current_index],
                'text' => $text . "\n"
            ]
        ]);
        $current_index += mb_strlen($text . "\n", 'UTF-8');
        return [
            'request' => $request,
            'current_index' => $current_index
        ];
    }

    

    private function parseHtmlNode($node, &$requests, &$current_index) {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType == XML_TEXT_NODE) {
                $data = $this->addTextNode($child->textContent,$current_index);
                $requests[] = $data['request'];
                $current_index = $data['current_index'];

            } elseif ($child->nodeType == XML_ELEMENT_NODE) {
               
                $start_index = $current_index;
                switch ($child->nodeName) {
                    case 'h1':
                    case 'h2':
                    case 'h3':
                        $heading_level = substr($child->nodeName, 1); // Extract the number from the tag name (e.g., 'h1' -> '1')
                        $data = $this->addTextNode($child->textContent,$current_index);
                        $requests[] = $data['request'];
                        $current_index = $data['current_index'];
                        $requests[] = new Google_Service_Docs_Request([
                            'updateParagraphStyle' => [
                                'range' => [
                                    'startIndex' => $start_index,
                                    'endIndex' => $current_index
                                ],
                                'paragraphStyle' => [
                                    'namedStyleType' => 'HEADING_' . $heading_level
                                ],
                                'fields' => 'namedStyleType'
                            ]
                        ]);
                        break;
                    case 'p':
                    default:
                        $data = $this->addTextNode($child->textContent,$current_index);
                        $requests[] = $data['request'];
                        $current_index = $data['current_index'];
                        $requests[] = new Google_Service_Docs_Request([
                            'updateParagraphStyle' => [
                                'range' => [
                                    'startIndex' => $start_index,
                                    'endIndex' => $current_index
                                ],
                                'paragraphStyle' => [
                                    'namedStyleType' => 'NORMAL_TEXT'
                                ],
                                'fields' => 'namedStyleType'
                            ]
                        ]);
                        break;
                }
            }
          
        }
    }

    public function readDocument($documentId){
        $doc = $this->service->documents->get($documentId); 
        $body = $doc->getBody()->getContent();
        $content = '';

        foreach ($body as $element) {
            $content .= $this->elementToHtml($element);
        }

        return $content;
    }

    private function elementToHtml($element){
        $html = '';

        if ($element->getParagraph()) {
            $paragraph = $element->getParagraph();
            $style = $paragraph->getParagraphStyle()->getNamedStyleType();

            switch ($style) {
                case 'HEADING_1':
                    $html .= '<h1>';
                    break;
                case 'HEADING_2':
                    $html .= '<h2>';
                    break;
                case 'HEADING_3':
                    $html .= '<h3>';
                    break;
                case 'NORMAL_TEXT':
                default:
                    $html .= '<p>';
                    break;
            }

            foreach ($paragraph->getElements() as $element) {
                if ($element->getTextRun()) {
                    $html .= htmlspecialchars($element->getTextRun()->getContent());
                }
            }

            switch ($style) {
                case 'HEADING_1':
                    $html .= '</h1>';
                    break;
                case 'HEADING_2':
                    $html .= '</h2>';
                    break;
                case 'HEADING_3':
                    $html .= '</h3>';
                    break;
                case 'NORMAL_TEXT':
                    $html .= '</p>';
                    break;
                default:
                    $html .= '</p>';
                    break;
            }
        }

        return $html;
    }
}
