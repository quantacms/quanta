<?php
namespace Quanta\Common;
/**
 * Implements hook_integrity_check().
 *
 * Fix the subnodes translate files.
 * 
 * Example for use this command: ./doctor application-name integrity_check --path=requested-node --subnodes=description,welcome
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function integrity_doctor_integrity_check(Environment $env, array $vars) {
  /** @var Doctor $doctor */
  $doctor = $vars['doctor'];
  $doctor->op(t('Running integrity check hooks...'));
  //get the requested path from the command
  $path = $doctor->getData('path');
 //get the subnodes from command or set the deafult one
  $subnodes_types = $doctor->getData('subnodes') ? explode(',',$doctor->getData('subnodes')) : ['description'];
  if($path){
    integrity_check_parent_nodes($env,$path,$subnodes_types);
    $doctor->ok(t('Done!'));
  }
  else{
    $subnodes_targets = array(
      'hosts' => array(
        'description',
        'welcome',
        'rooms' => array(
          'reception' => array(
            'title' => 'Reception room'
          )
        ),
        'host-manual-about-media',
        'host-manual-wifi-media',
        'host-manual-checkin-media',
        'host-manual-waste-media',
        'host-manual-services-media',
        'host-manual-rules-media',
        'host-manual-contacts-media',
        'host-manual-other-media',
        'feedbacks'
      ),
      'experiences' => array(
        'description',
      ),
      'service-types' => array(
        'description',
      ),
      'services' => array(
        'description',
      ),
    'businesses' => array(
      'shifts',
      'closures',
      'bookings' => array(
        'canceled' =>array(
          'title' => 'Canceled'
        ),
        'confirmed'=>array(
          'title' => 'Confirmed'
        ),
        'expired'=>array(
          'title' => 'Expired'
        ),
        'paid'=>array(
          'title' => 'Paid'
        ),
        'refunded'=>array(
          'title' => 'Refunded'
        ),
        'unpaid'=>array(
          'title' => 'Unpaid'
        ),
        ),
      'description',
   
    ),
    'zones' => array(
      'description',
      ),
    );
    foreach ($subnodes_targets as $db_node_name => $db_subnodes_types) {
      integrity_check_parent_nodes($env,$db_node_name,$db_subnodes_types);
      $doctor->ok('node: '.$db_node_name . ' done!');
    }
    $doctor->ok(t('Done!'));
  }
  //check .gitkeep file
  integrity_doctor_setup($env,$vars);
}

function integrity_check_parent_nodes($env,$path,$subnodes_types){

    //get the requested node which is the parent node
    $parent_node = \Quanta\Common\NodeFactory::load($env, $path);
    if($parent_node->exists){
      $attributes = [];
      //get the node names which is the target ti get the childs
      $taregt = $parent_node->getName();
      //get child nodes
      $dirlist = new \Quanta\Common\DirList($env,  $taregt, 'list', $attributes, 'integrity');
      $nodes = $dirlist->getItems();
      
      //the nodes that must be translated which contains  data_<language>.json
      $translated_nodes = array(
        'description',
        'welcome',
      );
      $nodes_have_childrens = array(
        'rooms',
        'shifts',
        'closures'
      );
      //get the language
      $language= \Quanta\Common\Localization::getLanguage($env);
      integrity_check_node($parent_node);
        foreach ($nodes as $node) {
          integrity_check_node($node);
          foreach ($subnodes_types as $key => $subnode_type) {
            if(is_array($subnode_type)){
              //subnode name example : node-name-description
              $subnode_name = $node->name . '-' . $key;
              //load the subnode
              $subnode = \Quanta\Common\NodeFactory::load($env, $subnode_name);
              integrity_check_sub_nodes($env,$node,$subnode,$key,$translated_nodes);

              foreach ($subnode_type as $child => $child_data) {
                //subnode name example : node-name-description
                $subnode_name = $node->name . '-' . $key . '-' .$child ;
                //load the subnode
                $subnode = \Quanta\Common\NodeFactory::load($env, $subnode_name);
                $parent_node = \Quanta\Common\NodeFactory::load($env, $node->name . '-' . $key);
                integrity_check_sub_nodes($env,$parent_node,$subnode,$child,$translated_nodes,$child_data);
              }
            }else{
              //subnode name example : node-name-description
              $subnode_name = $node->name . '-' . $subnode_type;
              //load the subnode
              $subnode = \Quanta\Common\NodeFactory::load($env, $subnode_name);
              integrity_check_sub_nodes($env,$node,$subnode,$subnode_type,$translated_nodes);
              
              //check if the sub-node has childrens
              if(in_array($subnode_type,$nodes_have_childrens)){
                $childs_dirlist = new \Quanta\Common\DirList($env,  $subnode_name, 'list', $attributes, 'integrity');
                $childs = $childs_dirlist->getItems();
                foreach ($childs as $child) {
                //get the language files inside subnode
                $child_language_files = glob($child->path . '/data_*.json'); 
                integrity_check_sub_nodes($env,$subnode,$child,$subnode_type,$translated_nodes);
                }
                
              }
            }
          }
          		
        }
    }

}

function integrity_check_node($node){
  //check if parent node has translation files
  $language_files = glob($node->path . '/data_*.json');
  if(count($language_files)){
    foreach ($language_files as $language_file){
      if(file_exists($node->path.'/data.json')){
        // If data.json exists, delete data_<language>.json
        unlink($language_file);
      }
      else{
        // If data.json not exist, rename data_<language>.json to data.json
        rename($language_file, $node->path.'/data.json');
      }
    }
    
  }
}

function integrity_check_sub_nodes($env,$node,$subnode,$subnode_type,$translated_nodes,$payload_data = []){
  //get the language files inside subnode
  $subnode_language_files = glob($subnode->path . '/data_*.json'); 
  $language= \Quanta\Common\Localization::getLanguage($env); 
  //check if subnode not exist or the subnode exist but it is a translated node and not has any translated file          
  if (!$subnode->exists || (in_array($subnode_type,$translated_nodes) && !count($subnode_language_files))) {
    $data_array = array('author' => $node->getAuthor());
    if(in_array($subnode_type,$translated_nodes)){
      //add title,body and teaser to the new node
      $data_array['language']= $language;
      $data_array['title']= $node->getTitle();
      $data_array['body']= $node->getBody();
      $data_array['teaser']= $node->getTeaser();
    }
    if(is_array($payload_data) && count($payload_data)){
			$data_array['title']= isset($payload_data['title']) ? $payload_data['title'] : null;
			$data_array['body']= isset($payload_data['body']) ? $payload_data['body'] : null;
			$data_array['teaser']= isset($payload_data['teaser']) ? $payload_data['teaser'] : null;
		}
    //build the node
    $subnode_name = $node->name . '-' . $subnode_type;
    \Quanta\Common\NodeFactory::buildNode($env, $subnode_name, $node->name,$data_array);
  }
  
  if(in_array($subnode_type,$translated_nodes) && file_exists($subnode->path.'/data.json')){
    //check if the node is translated but contain data.json file

    //check if the node has translated file
    $language_files = glob($subnode->path . '/data_*.json');  
    if(count($language_files)){
      // If data_<language>.json exists, delete data.json
      unlink($subnode->path.'/data.json');
    }
    else{
      // If data_<language>.json does not exist, rename data.json to data_<language>.json
      $translated_file = $subnode->path.'/data_'.$language.'.json';
      rename($subnode->path.'/data.json', $translated_file);
    }
    

  }elseif(!in_array($subnode_type,$translated_nodes) && count($subnode_language_files)){
    //check if the node is non-translated but contain data<language>.json file
  
    foreach ($subnode_language_files as $language_file){
      if(file_exists($subnode->path.'/data.json')){
        // If data.json exists, delete data_<language>.json
        unlink($language_file);
      }
      else{
        // If data.json not exist, rename data_<language>.json to data.json
        rename($language_file, $subnode->path.'/data.json');
      }
    }
   
  }
}
  
/**
 * Implements hook_doctor_setup().
 * Main setup of users.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   Miscellaneous environment / page variables.
 */
function integrity_doctor_setup(Environment $env, array $vars) {
  /** @var Doctor $doctor */
  $doctor = $vars['doctor'];
  // Get the application root directory from the environment
  $app_root = $env->dir['docroot'];
  // Path to the .gitkeep file in the directory
  $gitkeep_file = $app_root . '/.gitkeep';
  // Create an empty .gitkeep file if it doesn't already exist
  if (!file_exists($gitkeep_file)) {
    file_put_contents($gitkeep_file, '');
  }
  $doctor->ok(t('.gitkeep file was added successfully'));
}
