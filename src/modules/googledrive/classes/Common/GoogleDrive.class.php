<?php
namespace Quanta\Common;



use Google_Client;
use Google_Service_Drive;
use Google_Service_Drive_Permission;


/**
 * Class GoogleDocs
 */
class GoogleDrive extends \Quanta\Common\GoogleClient{

    const FILE_READER_ROLE = 'reader';
    const FILE_COMMENTER_ROLE = 'commenter';
    const FILE_WRITER_ROLE = 'writer';

  

    public function __construct(&$env){
       // Call the parent class constructor at the end
       parent::__construct($env,[Google_Service_Drive::DRIVE]);
       $this->service = new Google_Service_Drive($this->client);

    }

     /**
     * Change file permission.
     * @param String $fileId
     * @param Array $emails
     * @param String $role
     */
    public function changePermissions($fileId, $emails, $role) {
      foreach ($emails as $email) {
          $permissions = new Google_Service_Drive_Permission(array(
              'type' => 'user',
              'role' => $role,
              'emailAddress' => $email
          ));
        
          $this->service->permissions->create($fileId, $permissions);
      }   
    }

 


}
