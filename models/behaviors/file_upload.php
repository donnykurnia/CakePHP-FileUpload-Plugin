<?php
/**
  * Behavior for file uploads
  * 
  * Example Usage:
  *
  * @example 
  *   var $actsAs = array('FileUpload.FileUpload');
  *
  * @example 
  *   var $actsAs = array(
  *     'FileUpload.FileUpload' => array(
  *       'uploadDir'    => WEB_ROOT . DS . 'files',
  *       'fields'       => array('name' => 'file_name', 'type' => 'file_type', 'size' => 'file_size'),
  *       'allowedTypes' => array('pdf' => array('application/pdf')),
  *       'required'    => false,
  *       'unique' => false //filenames will overwrite existing files of the same name. (default true)
  *       'fileNameFunction' => 'sha1' //execute the Sha1 function on a filename before saving it (default false)
  *     )
  *    )
  *
  *
  * @note: Please review the plugins/file_upload/config/file_upload_settings.php file for details on each setting.
  * @version: since 6.1.0
  * @author: Nick Baker, modified by Donny Kurnia
  * @link: http://www.webtechnick.com
  */
App::import('Vendor', 'FileUpload.uploader');
App::import('Config', 'FileUpload.file_upload_settings');
class FileUploadBehavior extends ModelBehavior {
  
  /**
    * Uploader is the uploader instance of class Uploader. This will handle the actual file saving.
    */
  var $Uploader = array();
  
  function setFileUploadOption(&$Model, $key, $value) {
    $this->options[$Model->alias][$key] = $value;
    $this->Uploader[$Model->alias]->setOption($key, $value);
  }
  
  /**
    * Setup the behavior
    */
  function setUp(&$Model, $options = array()){
    $FileUploadSettings = new FileUploadSettings;
    if(!is_array($options)){
      $options = array();
    }
    $this->options[$Model->alias] = array_merge($FileUploadSettings->defaults, $options);
        
    $uploader_settings = $this->options[$Model->alias];
    $uploader_settings['uploadDir'] = $this->options[$Model->alias]['forceWebroot'] ? WWW_ROOT . $uploader_settings['uploadDir'] : $uploader_settings['uploadDir']; 
    $uploader_settings['fileModel'] = $Model->alias;
    $this->Uploader[$Model->alias] = new Uploader($uploader_settings);
  }
 
  /**
    * beforeSave if a file is found, upload it, and then save the filename according to the settings
    *
    */
  function beforeSave(&$Model, $options){
    if ( $Model->id ) {
      //backup submitted data
      $submittedData = $Model->data;
      //get current file in database table
      $data = $Model->read(null, $Model->id);
      if ( $data AND $data[$Model->alias][$this->options[$Model->alias]['fields']['name']] != '' ) {
        //save current file to be deleted later
        $Model->currentFiles[] = $data[$Model->alias][$this->options[$Model->alias]['fields']['name']];
      }
      //restore submitted data
      $Model->data = $submittedData;
    }
    return $Model->beforeSave();
  }

  /**
    * beforeSave if a file is found, upload it, and then save the filename according to the settings
    *
    */
  function afterSave(&$Model, $created){
    if(isset($Model->data[$Model->alias][$this->options[$Model->alias]['fileVar']])){
      $file = $Model->data[$Model->alias][$this->options[$Model->alias]['fileVar']];
      $this->Uploader[$Model->alias]->file = $file;
      
      if($this->Uploader[$Model->alias]->hasUpload()){
        $fileName = $this->Uploader[$Model->alias]->processFile();
        $id = $Model->{$Model->primaryKey};
        if ( $fileName AND $id > 0 ) {
          //update data in database
          $updateM[$Model->alias] = array($Model->primaryKey => $id);
          $updateM[$Model->alias][$this->options[$Model->alias]['fields']['name']] = $fileName;
          $updateM[$Model->alias][$this->options[$Model->alias]['fields']['real_name']] = $file['name'];
          $updateM[$Model->alias][$this->options[$Model->alias]['fields']['size']] = $file['size'];
          $updateM[$Model->alias][$this->options[$Model->alias]['fields']['type']] = $file['type'];
          $result = $Model->save($updateM, array('callbacks' => false));
          //cleanup
          if ( $result ) {
            //delete current files
            if ( count($Model->currentFiles) > 0 ) {
              foreach ( $Model->currentFiles as $currentFile ) {
                $this->Uploader[$Model->alias]->removeFile($currentFile);
              }
            }
          }
          //update failed
          else {
            //delete uploaded file
            $this->Uploader[$Model->alias]->removeFile($fileName);
          }
          return $result;
        }
        else {
          return false; // we couldn't save the file, return false
        }
        unset($Model->data[$Model->alias][$this->options[$Model->alias]['fileVar']]);
      }
      else {
        unset($Model->data[$Model->alias]);
      }
    }
    return $Model->afterSave($created);
  }
  
  /**
    * Updates validation errors if there was an error uploading the file.
    * presents the user the errors.
    */
  function beforeValidate(&$Model){
    if(isset($Model->data[$Model->alias][$this->options[$Model->alias]['fileVar']])){
      $file = $Model->data[$Model->alias][$this->options[$Model->alias]['fileVar']];
      $this->Uploader[$Model->alias]->file = $file;
      if($this->Uploader[$Model->alias]->hasUpload()){
        if($this->Uploader[$Model->alias]->checkFile() && $this->Uploader[$Model->alias]->checkType() && $this->Uploader[$Model->alias]->checkSize()){
          $Model->beforeValidate();
        }
        else {
          $Model->validationErrors[$this->options[$Model->alias]['fileVar']] = $this->Uploader[$Model->alias]->showErrors();
        }
      }
      else {
        if(isset($this->options[$Model->alias]['required']) && $this->options[$Model->alias]['required']){
          $Model->validationErrors[$this->options[$Model->alias]['fileVar']] = 'Select file to upload';
        }
      }
    }
    elseif(isset($this->options[$Model->alias]['required']) && $this->options[$Model->alias]['required']){
      $Model->validationErrors[$this->options[$Model->alias]['fileVar']] = 'No File';
    }
    return $Model->beforeValidate();
  }
  
  /**
    * Automatically remove the uploaded file.
    */
  function beforeDelete(&$Model, $cascade){
    $Model->recursive = -1;
    $data = $Model->read();
    if ( $data AND $data[$Model->alias][$this->options[$Model->alias]['fields']['name']] != '' ) {
      //save current file to be deleted later
      $Model->currentFiles[] = $data[$Model->alias][$this->options[$Model->alias]['fields']['name']];
    }
    return $Model->beforeDelete($cascade);
  }
    
  /**
    * Automatically remove the uploaded file.
    */
  function afterDelete(&$Model){
    //delete current files
    if ( count($Model->currentFiles) > 0 ) {
      foreach ( $Model->currentFiles as $currentFile ) {
        $this->Uploader[$Model->alias]->removeFile($currentFile);
      }
    }
    return $Model->afterDelete();
  }

}
