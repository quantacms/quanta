# quantacms
An innovative, DB-free Content Management System for web and application development, based on the principles of Quantum physics.

Originally designed by Aldo Tripiciano in 2015, Quanta is now proudly free and open source.


The Author
----------------------------------
Aldo Tripiciano is a seasoned italian IT Developer, consulting for 15+ years on the major CMS players (Drupal, Wordpress, Joomla, etc.) for global companies and organisations such as the United Nations and the European Commission. 
After mastering the top tools in the market, he decided to go one step beyond and build something new, to better fit the new generation of the web. 


Quanta's Features
----------------------------------

- Pre-defined installation profiles (including modules, themes and general entities)
- A customizable UI backend ("Shadow"), also used for overlay forms
- Easy creation and inline editing of forms
- A batch tool for install, update, diagnostic and repair ("Doctor")
- User management
- Forms management
- Workflow management (Draft->Published statuses, etc.)
- Multilingualism / Internationalization 
- High security (delegated on the OS file permissions - completely eliminating SQL injections and such)
- Big applications are easy to distribute on multiple machines, Virtualized or Cloud-based.

Quanta's Architecture
----------------------------------
Quanta is completely Object Oriented PHP, escaping the traditional MVC model and design patterns. 

- a 100% file-system based architecture structured on hierarchical system folders (no DB or SQL involved). 
- Internal caching and indexation of directories through an internal vocabulary
- JSON storage of data and metadata
- 100% Object Oriented PHP
- Template engine allowing creation and override individual template for individual or multiple entities
- qTags: special markup allowing creation of tags that are incapsulated into templates, allowing the creation of complex applications with huge reduction of coding times
- CSS Grid approach natively supported by qTags
- Node-level access control (roles, permissions, etc.)
- Views system


Pre-Requisites
----------------------------------
Quanta can only be installed on any UNIX-based OS (Linux, OSx, etc.). 

Apache 2.4+ or Nginx 1.15+
PHP 5.6+ (7 strongly advised!) including libraries: GD, CURL
Composer

For Apache users:
the rewrite and headers module must be enabled.

For Windows / XAMP users:
Quanta can only be installed on any UNIX-based OS (Linux, OSx, etc.). 
in order to run Quanta on Windows, you will have to install a VM (VMware, VirtualBox, etc.) 


Installation
-----------------

Detailed information about Quanta's installation process is available on the website: https://www.quantacms.com/installation-instructions/

