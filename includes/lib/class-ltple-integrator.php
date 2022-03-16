<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class LTPLE_Integrator_Google_Plus extends LTPLE_Client_Integrator {

	public function init_app() {
		
		$this->vendor = trailingslashit( dirname($this->parent->dir) ) . 'live-template-editor-app-google/vendor';		
		
		if( !file_exists($this->vendor . '/autoload.php') ){
			
			$message = '<div class="alert alert-danger">';
				
				$message .= 'Sorry, Google API is not yet available on this platform, please contact the dev team...';
					
			$message .= '</div>';

			$this->parent->session->update_user_data('message',$message);			
		}
		else{
			
			// include google SDK
			
			include_once($this->vendor . '/autoload.php');		

			if( isset($this->parameters['key']) ){
				
				$goo_api_project 		= array_search('goo_api_project', $this->parameters['key']);
				$goo_consumer_key 		= array_search('goo_consumer_key', $this->parameters['key']);
				$goo_consumer_secret 	= array_search('goo_consumer_secret', $this->parameters['key']);
				$goo_oauth_callback 	= $this->parent->urls->apps;

				if( !empty($this->parameters['value'][$goo_api_project]) && !empty($this->parameters['value'][$goo_consumer_key]) && !empty($this->parameters['value'][$goo_consumer_secret]) ){
				
					define('API_PROJECT', 		$this->parameters['value'][$goo_api_project]);
					define('CONSUMER_KEY', 		$this->parameters['value'][$goo_consumer_key]);
					define('CONSUMER_SECRET', 	$this->parameters['value'][$goo_consumer_secret]);
					define('OAUTH_CALLBACK', 	$goo_oauth_callback);
					
					$callback=parse_url(OAUTH_CALLBACK);
					define('JS_ORIGINS', $callback['scheme'].'://'.$callback['host']);
					
					$this->oauthConfig = json_decode('{"web":{"client_id":"'.CONSUMER_KEY.'","project_id":"'.API_PROJECT.'","auth_uri":"https://accounts.google.com/o/oauth2/auth","token_uri":"https://accounts.google.com/o/oauth2/token","auth_provider_x509_cert_url":"https://www.googleapis.com/oauth2/v1/certs","client_secret":"'.CONSUMER_SECRET.'","redirect_uris":["'.OAUTH_CALLBACK.'"],"javascript_origins":["'.JS_ORIGINS.'"]}}', true);
					
					//set client
					$this->client = new Google_Client();
					
					//Set the path to these credentials
					$this->client->setAuthConfig($this->oauthConfig);
					
					//Set the scopes required for the API you are going to call
					$this->client->addScope('https://www.googleapis.com/auth/plus.login');
					$this->client->addScope('https://www.googleapis.com/auth/plus.profile.emails.read');
					$this->client->addScope('https://www.googleapis.com/auth/plus.me');
					
					// set Approval Prompt
					$this->client->setApprovalPrompt('auto');
					
					// generates refresh token
					$this->client->setAccessType('offline');       

					// init action
			
					if( $action = $this->get_current_action() ){
					
						$this->init_action($action);
					}
				}
				else{
					
					$message = '<div class="alert alert-danger">';
						
						$message .= 'Sorry, Google Plus is not yet available on this platform, please contact the dev team...';
							
					$message .= '</div>';

					$this->parent->session->update_user_data('message',$message);					
				}
			}
		}
	}
	
	public function appConnect(){
		
		if( !empty($_REQUEST['action']) ){

			$this->parent->session->update_user_data('app',$this->app_slug);
			$this->parent->session->update_user_data('action',$_REQUEST['action']);
			$this->parent->session->update_user_data('ref',$this->get_ref_url());
			
			$this->oauth_url = $this->client->createAuthUrl();
		
			wp_redirect($this->oauth_url);
			echo 'Redirecting google-plus oauth...';
			exit;
		}			
		elseif(isset($_REQUEST['code'])){
			
			//get access_token
			
			$this->access_token =  $this->client->fetchAccessTokenWithAuthCode($_REQUEST['code']);				
			
			$this->reset_session();				
			
			//store access_token in session					
			
			$this->parent->session->update_user_data('access_token',$this->access_token);

			//set access_token	
			
			$this->client->setAccessToken($this->access_token);	
			
			//start the service
			$service=new Google_Service_Plus($this->client);

			//get user blogs
			
			$info = $service->people->get('me');
			
			if( !empty($info->emails[0]->type) && $info->emails[0]->type == 'account' ){
				
				// get blog id
				
				$this->access_token['profile_id'] = $info->id;					
				
				// get blog name
				
				preg_match('`^([^@]+)@gmail\.com`',$info->emails[0]->value,$matches);
				
				$profile_name = $matches[1];

				$this->access_token['profile_name'] = $profile_name;
				
				// store access_token in database		
				
				$app_title = wp_strip_all_tags( 'google-plus - ' . $profile_name );
				
				$app_item = get_page_by_title( $app_title, OBJECT, 'user-app' );
				
				if( empty($app_item) ){
					
					// create app item
					
					$app_id = wp_insert_post(array(
					
						'post_title'   	 	=> $app_title,
						'post_status'   	=> 'publish',
						'post_type'  	 	=> 'user-app',
						'post_author'   	=> $this->parent->user->ID
					));
					
					wp_set_object_terms( $app_id, $this->term->term_id, 'app-type' );
					
					// hook connected app
					
					do_action( 'ltple_google_plus_account_connected');
					
					$this->parent->apps->newAppConnected();
				}
				else{

					$app_id = $app_item->ID;
				}
					
				// update app item
					
				update_post_meta( $app_id, 'appData', json_encode($this->access_token,JSON_PRETTY_PRINT));
			}
			
			// store success message

			$message = '<div class="alert alert-success">';
					
				$message .= 'Congratulations, you have successfully connected a Google Plus account!';
						
			$message .= '</div>';

			$this->parent->session->update_user_data('message',$message);						
						
			if( $redirect_url = $this->parent->session->get_user_data('ref') ){
				
				wp_redirect($redirect_url);
				echo 'Redirecting google plus callback...';
				exit;	
			}
		}
		else{
					
			//flush session
					
			$this->reset_session();
		}
	}
	
	public function appImportImg(){
		
		if(!empty($_REQUEST['id'])){
		
			if( $this->app = $this->parent->apps->getAppData( $_REQUEST['id'], $this->parent->user->ID, true ) ){
				
				$this->client->setAccessToken($this->app);		

				if($this->client->isAccessTokenExpired()){  
					
					// refresh the token
				
					$this->client->refreshToken($this->app);
				}
				
				$service = new Google_Service_Plus($this->client);
				
				$activites = $service->activities->listActivities('me','public');

				$urls = [];
				
				if(!empty($activites->items)){
					
					foreach($activites->items as $item){

						if(!empty($item->object['attachments'])){
							
							foreach($item->object['attachments'] as $image){

								if($image['objectType']=='photo'){
								
									$img_title	= basename($image['fullImage']['url']);
									$img_url	= $image['fullImage']['url'];
									
									if(!get_page_by_title( $img_title, OBJECT, 'user-image' )){
										
										if($image_id = wp_insert_post(array(
									
											'post_author' 	=> $this->parent->user->ID,
											'post_title' 	=> $img_title,
											'post_content' 	=> $img_url,
											'post_type' 	=> 'user-image',
											'post_status' 	=> 'publish'
										))){
											
											wp_set_object_terms( $image_id, $this->term->term_id, 'app-type' );
										}
									}
								}								
							}						
						}
					}
				}
			}
		}
	}
}