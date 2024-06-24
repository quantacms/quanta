<?php
namespace Quanta\Common;
/**
 * Implements hook_rsync().
 *
 * Sync the data.
 * 
 * Example for use this command: ./doctor application-name rsync
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function sync_doctor_rsync(Environment $env, array $vars) {
  /** @var Doctor $doctor */
  $doctor = $vars['doctor'];
  $doctor->op(t('Running r-sync hooks...'));
  //get the requested data  from the command
  $enviroment  = $doctor->getData('env');
  $user = $doctor->getData('user');
  $ssh_key = $doctor->getData('ssh-key');
  $override = $doctor->getData('override');
  $override_recent = $doctor->getData('override-recent');
  $help = $doctor->getData('help');
 
   // Construct the command string
   $module_path = $env->getModulePath('sync');
   $script_path = "$module_path/assets/scripts/sync.sh";
   // Construct the command string using escapeshellarg for safety
   $command = escapeshellcmd($script_path) .
               ' --env ' . escapeshellarg($enviroment) .
               ' --user ' . escapeshellarg($user) .
               ' --ssh-key ' . escapeshellarg($ssh_key);

      if (!empty($override)) {
         $command .= " --override";
      }
      if (!empty($override_recent)) {
         $command .= " --override-recent $override_recent";
      }
      if (!empty($help)) {
         $command .= ' --help ' . escapeshellarg($help);
   }
   
      // Execute the command
   $output = shell_exec($command);
   
      // Handle the output if needed
      if ($output === null) {
      $doctor->ko(t('Failed to execute the sync command.'));
      } else {
      $doctor->op($output);
      }
}
  
