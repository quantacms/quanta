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
  if($path){
    //get the requested node which is the parent node
    $parent_node = \Quanta\Common\NodeFactory::load($env, $path);
    if($parent_node->exists){
      $attributes = [];
      //get the node names which is the target ti get the childs
      $taregt = $parent_node->getName();
      //get child nodes
      $dirlist = new \Quanta\Common\DirList($env,  $taregt, 'list', $attributes, 'integrity');
      $nodes = $dirlist->getItems();
      //get the subnodes from command or set the deafult one
      $subnodes = $doctor->getData('subnodes') ? explode(',',$doctor->getData('subnodes')) : ['description'];
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
        foreach ($nodes as $node) {
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
          foreach ($subnodes as $subnode_type) {
            //subnode name example : node-name-description
            $subnode_name = $node->name . '-' . $subnode_type;
            //load the subnode
            $subnode = \Quanta\Common\NodeFactory::load($env, $subnode_name);
            //get the language files inside subnode
            $subnode_language_files = glob($subnode->path . '/data_*.json');  
            //check if subnode not exist or the subnode exist and but it is a translated node and not has any translated file          
            if (!$subnode->exists || (in_array($subnode_type,$translated_nodes) && !count($subnode_language_files))) {
              $data_array = array('author' => $node->getAuthor());
              if(in_array($subnode_type,$translated_nodes)){
                //add title,body and teaser to the new node
                $data_array['language']= $language;
                $data_array['title']= $node->getTitle();
                $data_array['body']= $node->getBody();
                $data_array['teaser']= $node->getTeaser();
              }
              //build the node
              \Quanta\Common\NodeFactory::buildNode($env, $subnode_name, $node->name,$data_array);
            }elseif(in_array($subnode_type,$translated_nodes) && file_exists($subnode->path.'/data.json')){
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

            //check if the sub-node has childrens
            if(in_array($subnode_type,$nodes_have_childrens)){
              $childs_dirlist = new \Quanta\Common\DirList($env,  $subnode_name, 'list', $attributes, 'integrity');
              $childs = $childs_dirlist->getItems();
              foreach ($childs as $child) {
              //get the language files inside subnode
              $child_language_files = glob($child->path . '/data_*.json'); 
              //TODO: make this conditions in sepreate function
              if(in_array($subnode_type,$translated_nodes) && file_exists($child->path.'/data.json')){
                //check if the node is translated but contain data.json file
  
                if(count($child_language_files)){
                  // If data_<language>.json exists, delete data.json
                  unlink($child->path.'/data.json');
                }
                else{
                  // If data_<language>.json does not exist, rename data.json to data_<language>.json
                  $translated_file = $child->path.'/data_'.$language.'.json';
                  rename($child->path.'/data.json', $translated_file);
                }
                
  
              }elseif(!in_array($subnode_type,$translated_nodes) && count($child_language_files)){
                //check if the node is non-translated but contain data<language>.json file
              
                foreach ($child_language_files as $language_file){
                  if(file_exists($child->path.'/data.json')){
                    // If data.json exists, delete data_<language>.json
                    unlink($language_file);
                  }
                  else{
                    // If data.json not exist, rename data_<language>.json to data.json
                    rename($language_file, $child->path.'/data.json');
                  }
                }
               
              }
              }
              
            }
          }
          		
        }
     
      $doctor->ok(t('Done!'));
    }
   
   
  }
  else{
    $doctor->ko("Please provide me the path you needed. example --path=node-name\n");
  }
}
  
