# **WP Conditional Media Folder**

A WordPress plugin that conditionally saves media files to a custom folder based on filename or MIME type rules.

## **Features**

* **Custom Storage Path**: Save files anywhere on the server filesystem (absolute path).  
* **Custom URL Mapping**: Ensure files are served correctly by mapping the path to a URL.  
* **Advanced Rules Engine**:  
  * **Logic**: Rules are processed with OR logic. Conditions within a rule use AND logic.  
  * **Conditions**:  
    * Filename starts with  
    * Filename ends with  
    * Filename contains  
    * Min filename length  
    * Max filename length  
    * MIME Type (e.g., image/jpeg, application/pdf)  
* **Seamless Integration**: Files appear in the Media Library regardless of storage location.

## **Installation**

1. Clone this repository into wp-content/plugins/.  
2. Run composer install (if dependencies are added later).  
3. Activate via WP Admin.

## **Configuration**

Go to **Settings \> Conditional Media Folder**.

1. **Custom Folder Path**: Enter the absolute path (e.g., /var/www/static\_assets/special).  
2. **Custom Folder URL**: Enter the public URL (e.g., https://cdn.mysite.com/special).  
3. **Add Criteria**: Add as many rule sets as needed.

## **License**

[MIT](https://www.google.com/search?q=LICENSE)