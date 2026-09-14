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
      $skipped_nodes = array(
        'title',
        'body',
        'teaser',
        'skip_normalize'
      );
      //get the language
      $language= \Quanta\Common\Localization::getLanguage($env);
      integrity_check_node($parent_node, $env);
        foreach ($nodes as $node) {
          integrity_check_node($node, $env);
          foreach ($subnodes_types as $key => $subnode_type) {
            if(is_array($subnode_type)){
              //subnode name example : node-name-description
              $subnode_name = $node->name . '-' . $key;
              //load the subnode
              $subnode = \Quanta\Common\NodeFactory::load($env, $subnode_name);
              integrity_check_sub_nodes($env,$node,$subnode,$key,$translated_nodes,$subnode_type);

              foreach ($subnode_type as $child => $child_data) {
                if(in_array($child,$skipped_nodes)){
                  continue;
                }
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
                // integrity_check_sub_nodes() reads the child's languages
                // itself, through the index; the glob that used to be here
                // assigned to a variable nothing ever read.
                integrity_check_sub_nodes($env,$subnode,$child,$subnode_type,$translated_nodes);
                }
                
              }
            }
          }
          		
        }
    }

}

/**
 * Collapse a non-translated node's data_<lang>.json files onto data.json.
 *
 * The node database expresses every step of this: meta()['langs'] lists the
 * documents, deleteDoc() drops one, and getRaw() + putRaw() move one to another
 * language without re-encoding it (qdb/docs/api-contract.md §9). Each step
 * falls back to the filesystem on its own, so an absent extension changes
 * nothing.
 *
 * @param Node $node
 *   The node to repair.
 * @param Environment|null $env
 *   The Environment, to reach the node database.
 */
function integrity_check_node($node, $env = NULL){
  // $env stays optional so an old caller cannot fatal, but it is no longer
  // optional to HAVE one — the node database is where the repair happens, and
  // the node is carrying its Environment anyway.
  $db = ($env === NULL ? $node->getEnv() : $env)->db();
  // 'at': this repair is about the documents in THIS directory, so the
  // database is told where the node is rather than asked to resolve the name.
  $at = array('at' => $node->path);

  //check if parent node has translation files
  $langs = $db->langs($node->getName(), $at);
  $language_codes = array_values(array_filter($langs));
  $has_neutral = in_array('', $langs, TRUE);

  foreach ($language_codes as $lang){
    if($has_neutral){
      // If data.json exists, delete data_<language>.json
      $db->deleteDoc($node->getName(), $lang, $at);
    }
    else{
      // If data.json not exist, rename data_<language>.json to data.json.
      // Write the survivor first and drop the original second, so an
      // interruption leaves a duplicate this same check repairs next run,
      // rather than no document at all. putRaw() so the bytes are not
      // re-encoded on the way through.
      $raw = $db->raw($node->getName(), $lang, $at);
      if ($raw !== NULL && $db->putRaw($node->getName(), $raw, $at)) {
        $db->deleteDoc($node->getName(), $lang, $at);
      }
      // Every later language now collapses onto the neutral document.
      $has_neutral = TRUE;
    }
  }
}

function integrity_check_sub_nodes($env,$node,$subnode,$subnode_type,$translated_nodes,$payload_data = []){
  // The subnode's documents. 'at' names the directory this repair is about, so
  // the database answers about it rather than about wherever the globally
  // unique name resolves. $subnode_language_files keeps its original meaning —
  // the translated documents only, never the neutral one.
  $at = array('at' => $subnode->path);
  $db = $env->db();
  $subnode_langs = $db->langs($subnode->getName(), $at);
  $subnode_language_files = array_values(array_filter($subnode_langs));
  $subnode_has_neutral = in_array('', $subnode_langs, TRUE);
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
			$data_array['skip_normalize']= isset($payload_data['skip_normalize']) ? $payload_data['skip_normalize'] : null;
		}
    //build the node
    $subnode_name = $node->name . '-' . $subnode_type;
    \Quanta\Common\NodeFactory::buildNode($env, $subnode_name, $node->name,$data_array);
  }
  
  // buildNode() above may have just created the subnode, so re-read its
  // documents rather than trusting the snapshot taken before it ran. The
  // legacy code re-checked data.json here too, inside the branch below.
  $subnode_langs = $db->langs($subnode->getName(), $at);
  $subnode_language_files = array_values(array_filter($subnode_langs));
  $subnode_has_neutral = in_array('', $subnode_langs, TRUE);

  if(in_array($subnode_type,$translated_nodes) && $subnode_has_neutral){
    //check if the node is translated but contain data.json file

    //check if the node has translated file
    if(count($subnode_language_files)){
      // If data_<language>.json exists, delete data.json
      $db->deleteDoc($subnode->getName(), NULL, $at);
    }
    else{
      // If data_<language>.json does not exist, rename data.json to
      // data_<language>.json. Survivor first, original second, so an
      // interruption leaves a duplicate rather than nothing.
      $raw = $db->raw($subnode->getName(), NULL, $at);
      if ($raw !== NULL && $db->putRaw($subnode->getName(), $raw, $at + array('lang' => $language))) {
        $db->deleteDoc($subnode->getName(), NULL, $at);
      }
    }


  }elseif(!in_array($subnode_type,$translated_nodes) && count($subnode_language_files)){
    //check if the node is non-translated but contain data<language>.json file

    foreach ($subnode_language_files as $lang){
      if($subnode_has_neutral){
        // If data.json exists, delete data_<language>.json
        $db->deleteDoc($subnode->getName(), $lang, $at);
      }
      else{
        // If data.json not exist, rename data_<language>.json to data.json.
        // Survivor first, original second — see the note above.
        $raw = $db->raw($subnode->getName(), $lang, $at);
        if ($raw !== NULL && $db->putRaw($subnode->getName(), $raw, $at)) {
          $db->deleteDoc($subnode->getName(), $lang, $at);
        }
        // Every later language now collapses onto the neutral document.
        $subnode_has_neutral = TRUE;
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
