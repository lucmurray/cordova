<?php defined('BASEPATH') OR exit('No direct script access allowed');

class Auth extends MY_Controller {

	function __construct()
	{
		parent::__construct();
		$this->load->library('ion_auth');
		$this->load->library('form_validation');

		// Load MongoDB library instead of native db driver if required
		$this->config->item('use_mongodb', 'ion_auth') ?
		$this->load->library('mongo_db') :

		$this->form_validation->set_error_delimiters($this->config->item('error_start_delimiter', 'ion_auth'), $this->config->item('error_end_delimiter', 'ion_auth'));

		$this->lang->load('ion_auth');
		$this->lang->load('auth');
		$this->load->helper('language');
	}

	/**
   * Index
   *
   * Display user list. Redirect if user is not an admin.
   */
	function index()
	{

		if ( ! $this->ion_auth->logged_in())
		{
			// Not logged in -- redirect them to the login page
			redirect('login', 'refresh');
		}
		elseif ( ! $this->ion_auth->is_admin())
		{
			// Logged in but not an admin
      // -- redirect them to the home page because they must be an administrator to view this
			redirect('variations/unreleased', 'refresh');
		}
		else
		{
			// Logged in as admin -- list the users
			$data['users'] = $this->ion_auth->users()->result();
			foreach ($data['users'] as $k => $user)
			{
				$data['users'][$k]->groups = $this->ion_auth->get_users_groups($user->id)->result();
			}
      $data['title'] = 'Users';
      $data['content'] = 'auth/users';

      $this->load->view($this->editor_layout, $data);
		}
	}

  /**
   * Login
   *
   * Loads a login page and uses the Ion Auth library to log the user in.
   */
  public function login() {
    redirect_all_members(); // Signed in members cannot view this page

    $data['title'] = 'Log in';
    $data['content'] = 'auth/login';

    // Check for previous "remember me" preference (in cookies)
    if (isset($_COOKIE['rememberme'])) {
  	  $data['rememberme'] = 'checked';
    }
    else {
  	  $data['rememberme'] = '';
    }

    // Get any methods of external authentication
    $data['extauths'] = $this->config->item('external_auth');

    $this->load->library('form_validation');
    $this->form_validation->set_rules('identity', 'Username', 'trim|required');
    $this->form_validation->set_rules('password', 'Password', 'required');

    if ($this->form_validation->run() !== false) {
      
		  // check for current "remember me"
		  if (isset($_POST['remember'])) {
  		  $remember = TRUE;
        // Set cookie to allow auto-selection of the "remember me" checkbox
        setcookie('rememberme', 'checked', time()+3600*24*30); // expires in 30 days
      }
      else {
  		  $remember = FALSE;
        setcookie('rememberme', '', time()-3600); // delete cookie on next page load
      }

		  if ($this->ion_auth->login($this->input->post('identity'), $this->input->post('password'), $remember))
		  {
		  	// login successful!
        $user = $this->ion_auth->user()->row();

        // Who should the welcome message be addressed to?
        if ($user->first_name) {
          $name = $user->first_name;
        }
        else {
          $name = $user->username;
        }

        // Set welcome message
        if ($name) {
		  	  $this->session->set_flashdata('success', '<p>Welcome, '.$name.'!</p>');
        }
        else {
		  	  $this->session->set_flashdata('success', '<p>Welcome!</p>');
        }

        // Log the login!
        $username = $user->username;
        activity_log("User '$username' logged in", 'login');

		  	redirect('variations/unreleased');
		  }
		  else {
		  	// login unsuccessful :(
		  	// redirect them back to the login page
		  	$this->session->set_flashdata('error', '<p>The username or password was incorrect. Please try again.</p>');
		  	redirect('login'); // use redirects instead of loading views for compatibility with MY_Controller libraries
		  }

    }

    $this->load->view($this->editor_layout, $data);
  }

  /**
   * Logout
   */
	function logout()
	{
		$this->data['title'] = "Logout";

    // Log the logout!
    $username = $this->ion_auth->user()->row()->username;
    activity_log("User '$username' logged out", 'logout');

		// log the user out
		$logout = $this->ion_auth->logout();

		// redirect them to the login page
		$this->session->set_flashdata('message', $this->ion_auth->messages());
		redirect('login', 'refresh');
    $this->load->view($this->editor_layout, $data);
	}

  /** 
   * Logs
   */
  function logs() {
    redirect_all_nonadmin();

    $data['title'] = 'Activity logs';
    $data['content'] = 'auth/logs';

    $filename = $this->config->item('activity_log_path');

    $data['logs'] = array();
    if ( ! file_exists($filename)) {
      // No activity log file
      $data['header'] = 'No activity logs';
    }
    else {
      $data['header'] = 'Activity logs';

      // Delete activity log
      if (isset($_POST['reset-logs'])) {
        unlink($filename);
        redirect(current_url());
      }
  
      // Read in log file, newest logs first
      $lines = array_reverse(file($this->config->item('activity_log_path'), FILE_IGNORE_NEW_LINES));
      foreach($lines as $line) {
        $data['logs'][] = array_combine(array('activity', 'date', 'message'), explode("\t", $line));
      }
    }
  
    $this->load->view($this->editor_layout, $data);
  }

  /**
   * Signup
   */
  public function signup() {

    redirect_all_members();

    $data['title'] = 'Sign up';
    $data['content'] = 'auth/signup';

    $this->load->library('form_validation');
    $this->form_validation->set_rules('username', 'Username', 'trim|required|alpha_dash|is_unique[users.username]');
    $this->form_validation->set_rules('email', 'Email', 'trim|required|valid_email|is_unique[users.email]');
    $this->form_validation->set_rules('first_name', 'First name', 'trim|required');
    $this->form_validation->set_rules('last_name', 'Last name', 'trim|required');
    $this->form_validation->set_rules('password', 'Password', 'required|min_length[6]');
    $this->form_validation->set_message('is_unique', 'That %s already has an account.');
    $this->form_validation->set_message('alpha_dash', 'The %s may only contain letters, numbers, underscores, and dashes.');

    if ($this->form_validation->run() !== false) {
      // passed validation
      $username = $this->input->post('username');
      $password = $this->input->post('password');
      $email = $this->input->post('email');
      $additional_data = array(
        'first_name' => $this->input->post('first_name'),
        'last_name' => $this->input->post('last_name'),
      );
      if ($this->ion_auth->register(
                                     $username, 
                                     $password,
                                     $email, 
                                     $additional_data
                                   )) {

        // registration successful!
        $this->session->set_flashdata('success', '<p>Congratulations! You may now log in!</p>');
        redirect('login'); 
      }
      else {
        // registration failed
		   	$this->session->set_flashdata('error', $this->ion_auth->errors());
		   	redirect('signup'); 
      }
    }

    $this->load->view($this->editor_layout, $data);
  }

  /**
   * External Auth
   *
   * Calls on a model to handle external authentication.
   * Useful for cases such as logging in via University servers,
   * LDAP, Google authentication, etc.
   *
   * Place your external authentication model in
   * application/models/external_auth/PREFIX_auth_model.php
   * For example, if you are using Google authentication, then put it in
   * application/models/external_auth/google_auth_model.php
   *
   * In your model create a function called authenticate(), which will be called
   * from here to begin the external authentication process.
   *
   * The model should be in charge of:
   *   1) Authenticating the user via external means
   *   2) Logging in the user if they're registered to use the site
   *   3) Error handling (incorrect username/password, non-registered user, etc.)
   *
   * @author  Sean Ephraim
   * @access  public
   * @param   string  Prefix of the model to use for ext. auth.
   * @return  void
   */
	function external_auth($prefix)
	{
    redirect_all_members(); // Signed in members cannot view this page

    $extauths = $this->config->item('external_auth');
    if ( ! array_key_exists($prefix, $extauths)) {
      die("External authentication has not been enabled for '$prefix'.");
    }

    $this->load->model('external_auth/'.$prefix.'_auth_model', 'ext_auth_model');
    $this->ext_auth_model->authenticate();
  }

  /**
   * Change Password
   */
	function change_password()
	{
		$this->form_validation->set_rules('old', $this->lang->line('change_password_validation_old_password_label'), 'required');
		$this->form_validation->set_rules('new', $this->lang->line('change_password_validation_new_password_label'), 'required|min_length[' . $this->config->item('min_password_length', 'ion_auth') . ']|max_length[' . $this->config->item('max_password_length', 'ion_auth') . ']|matches[new_confirm]');
		$this->form_validation->set_rules('new_confirm', $this->lang->line('change_password_validation_new_password_confirm_label'), 'required');

		if (!$this->ion_auth->logged_in())
		{
			redirect('login', 'refresh');
		}

		$user = $this->ion_auth->user()->row();

		if ($this->form_validation->run() == false)
		{
			//display the form
			//set the flash data error message if there is one
		  $error = (validation_errors() ? validation_errors() : ($this->ion_auth->errors() ? $this->ion_auth->errors() : $this->session->flashdata('message')));
      if ( ! empty($error)) {
		    $this->session->set_flashdata('error', $error);
		    redirect(current_url());
      }
      

			$this->data['min_password_length'] = $this->config->item('min_password_length', 'ion_auth');
			$this->data['old_password'] = array(
				'name' => 'old',
				'id'   => 'old',
				'type' => 'password',
			);
			$this->data['new_password'] = array(
				'name' => 'new',
				'id'   => 'new',
				'type' => 'password',
				'pattern' => '^.{'.$this->data['min_password_length'].'}.*$',
			);
			$this->data['new_password_confirm'] = array(
				'name' => 'new_confirm',
				'id'   => 'new_confirm',
				'type' => 'password',
				'pattern' => '^.{'.$this->data['min_password_length'].'}.*$',
			);
			$this->data['user_id'] = array(
				'name'  => 'user_id',
				'id'    => 'user_id',
				'type'  => 'hidden',
				'value' => $user->id,
			);

			//render
      $this->data['title'] = 'Change password';
      $this->data['content'] = 'auth/change_password';

      $this->load->view($this->editor_layout, $this->data);
		}
		else
		{
			$identity = $this->session->userdata($this->config->item('identity', 'ion_auth'));

			$change = $this->ion_auth->change_password($identity, $this->input->post('old'), $this->input->post('new'));

			if ($change)
			{
				//if the password was successfully changed
				$this->session->set_flashdata('success', $this->ion_auth->messages());
				$this->logout();
			}
			else
			{
				$this->session->set_flashdata('error', $this->ion_auth->errors());
				redirect('changepassword', 'refresh');
			}
		}
	}

  /**
   * Forgot Password
   */
  public function forgot_password() {
    $data['title'] = 'Forgot password';
    $data['content'] = 'auth/forgot_password';

    $this->load->library('form_validation');
		$this->form_validation->set_rules('identity', 'Email', 'trim|required|valid_email');
    $this->form_validation->set_message('required', 'You must enter your email address.');

		if ($this->form_validation->run() !== false) {
      // passed validation!
      $identity = $this->input->post('identity');

      if ($this->ion_auth->email_check($identity)) {
        // email exists!
        
			  // load the user with that email
			  $config_tables = $this->config->item('tables', 'ion_auth');
			  $user = $this->db->where('email', $this->input->post('email'))->limit('1')->get($config_tables['users'])->row();

			  // run the forgotten password method to email an activation code to the user
			  $success = $this->ion_auth->forgotten_password($user->{$this->config->item('identity', 'ion_auth')});
        
		    if ($success) {
          // email sent successfully!
          $this->session->set_flashdata('success', '<p>An email has been sent to you with instructions on regaining access to your account.</p>');
          redirect('/login');
		    }
		    else {
          // problems with sending email!
          $this->session->set_flashdata('error', $this->ion_auth->errors());
          redirect("forgotpassword");
		    }
			}
		  else {
        // email/username not found!
        $this->session->set_flashdata('error', '<p>The email address was not found. Please try again.</p>');
        redirect('forgotpassword');
		  }
		}

    $this->load->view($this->editor_layout, $data);
	}

	//reset password - final step for forgotten password
	public function reset_password($code = NULL)
	{
		if (!$code)
		{
			show_404();
		}

		$user = $this->ion_auth->forgotten_password_check($code);

		if ($user)
		{
			//if the code is valid then display the password reset form

			$this->form_validation->set_rules('new', $this->lang->line('reset_password_validation_new_password_label'), 'required|min_length[' . $this->config->item('min_password_length', 'ion_auth') . ']|max_length[' . $this->config->item('max_password_length', 'ion_auth') . ']|matches[new_confirm]');
			$this->form_validation->set_rules('new_confirm', $this->lang->line('reset_password_validation_new_password_confirm_label'), 'required');

			if ($this->form_validation->run() == false)
			{
				//set the flash data error message if there is one
		    $error = (validation_errors() ? validation_errors() : ($this->ion_auth->errors() ? $this->ion_auth->errors() : $this->session->flashdata('message')));
        if ( ! empty($error)) {
		      $this->session->set_flashdata('error', $error);
		      redirect(current_url());
        }

				//display the form
				$this->data['min_password_length'] = $this->config->item('min_password_length', 'ion_auth');
				$this->data['new_password'] = array(
					'name' => 'new',
					'id'   => 'new',
				'type' => 'password',
					'pattern' => '^.{'.$this->data['min_password_length'].'}.*$',
				);
				$this->data['new_password_confirm'] = array(
					'name' => 'new_confirm',
					'id'   => 'new_confirm',
					'type' => 'password',
					'pattern' => '^.{'.$this->data['min_password_length'].'}.*$',
				);
				$this->data['user_id'] = array(
					'name'  => 'user_id',
					'id'    => 'user_id',
					'type'  => 'hidden',
					'value' => $user->id,
				);
				$this->data['csrf'] = $this->_get_csrf_nonce();
				$this->data['code'] = $code;

				//render
        $this->data['title'] = 'Reset password';
        $this->data['content'] = 'auth/reset_password';
  
        $this->load->view($this->editor_layout, $this->data);
        
			}
			else
			{
				// do we have a valid request?
				if ($this->_valid_csrf_nonce() === FALSE || $user->id != $this->input->post('user_id'))
				{

					//something fishy might be up
					$this->ion_auth->clear_forgotten_password_code($code);

					show_error($this->lang->line('error_csrf'));

				}
				else
				{
					// finally change the password
					$identity = $user->{$this->config->item('identity', 'ion_auth')};

					$change = $this->ion_auth->reset_password($identity, $this->input->post('new'));

					if ($change)
					{
						//if the password was successfully changed
						$this->session->set_flashdata('success', $this->ion_auth->messages());
						$this->logout();
					}
					else
					{
						$this->session->set_flashdata('error', $this->ion_auth->errors());
						redirect('resetpassword/' . $code, 'refresh');
					}
				}
			}
		}
		else
		{
			//if the code is invalid then send them back to the forgot password page
			$this->session->set_flashdata('error', $this->ion_auth->errors());
			redirect("auth/forgot_password", 'refresh');
		}
	}


	//activate the user
	function activate($id, $code=false)
	{
		if ($code !== false)
		{
			$activation = $this->ion_auth->activate($id, $code);
		}
		else if ($this->ion_auth->is_admin())
		{
			$activation = $this->ion_auth->activate($id);
		}

		if ($activation)
		{
			//redirect them to the auth page
			$this->session->set_flashdata('success', $this->ion_auth->messages());
			redirect("auth", 'refresh');
		}
		else
		{
			//redirect them to the forgot password page
			$this->session->set_flashdata('error', $this->ion_auth->errors());
			redirect("auth/forgot_password", 'refresh');
		}
	}

	//deactivate the user
	function deactivate($id = NULL)
	{
		$id = $this->config->item('use_mongodb', 'ion_auth') ? (string) $id : (int) $id;

		$this->load->library('form_validation');
		$this->form_validation->set_rules('confirm', $this->lang->line('deactivate_validation_confirm_label'), 'required');
		$this->form_validation->set_rules('id', $this->lang->line('deactivate_validation_user_id_label'), 'required|alpha_numeric');

		if ($this->form_validation->run() == FALSE)
		{
			// insert csrf check
			$this->data['csrf'] = $this->_get_csrf_nonce();
			$this->data['user'] = $this->ion_auth->user($id)->row();

      $this->data['title'] = 'Deactivate user';
      $this->data['content'] = 'auth/deactivate_user';

      $this->load->view($this->editor_layout, $this->data);
      
		}
		else
		{
			// do we really want to deactivate?
			if ($this->input->post('confirm') == 'yes')
			{
				// do we have a valid request?
				if ($this->_valid_csrf_nonce() === FALSE || $id != $this->input->post('id'))
				{
					show_error($this->lang->line('error_csrf'));
				}

				// do we have the right userlevel?
				if ($this->ion_auth->logged_in() && $this->ion_auth->is_admin())
				{
					$this->ion_auth->deactivate($id);
				}
			}

			//redirect them back to the auth page
			redirect('auth', 'refresh');
		}
	}

	//create a new user
	function create_user()
	{
		$this->data['title'] = "Create User";

		if (!$this->ion_auth->logged_in() || !$this->ion_auth->is_admin())
		{
			redirect('auth', 'refresh');
		}

		//validate form input
		$this->form_validation->set_rules('first_name', $this->lang->line('create_user_validation_fname_label'), 'trim|xss_clean');
		$this->form_validation->set_rules('last_name', $this->lang->line('create_user_validation_lname_label'), 'trim|xss_clean');
		$this->form_validation->set_rules('username', $this->lang->line('create_user_validation_username_label'), 'trim|required|xss_clean');
		$this->form_validation->set_rules('email', $this->lang->line('create_user_validation_email_label'), 'trim|required|valid_email');
		$this->form_validation->set_rules('phone', $this->lang->line('create_user_validation_phone_label'), 'trim|xss_clean');
		$this->form_validation->set_rules('company', $this->lang->line('create_user_validation_company_label'), 'trim|xss_clean');
		$this->form_validation->set_rules('password', $this->lang->line('create_user_validation_password_label'), 'min_length[' . $this->config->item('min_password_length', 'ion_auth') . ']|max_length[' . $this->config->item('max_password_length', 'ion_auth') . ']|matches[password_confirm]');
    
    // Require a password if the user won't be using an external form of authentication
    if ($this->input->post('externalauth') == NULL) {
		  $this->form_validation->set_rules('password', $this->lang->line('create_user_validation_password_label'), 'required|min_length[' . $this->config->item('min_password_length', 'ion_auth') . ']|max_length[' . $this->config->item('max_password_length', 'ion_auth') . ']|matches[password_confirm]');
		  $this->form_validation->set_rules('password_confirm', $this->lang->line('create_user_validation_password_confirm_label'), 'required');
    }

		if ($this->form_validation->run() == true)
		{
			$username = $this->input->post('username');
			$email    = $this->input->post('email');
			$password = $this->input->post('password');

      // If no password is supplied, generate a random one
      if (empty($password)) {
        $this->load->helper('string');
        $password = random_string('alnum', $this->config->item('max_password_length', 'ion_auth'));
      }

			$additional_data = array(
				'first_name' => $this->input->post('first_name'),
				'last_name'  => $this->input->post('last_name'),
				'company'    => $this->input->post('company'),
				'phone'      => $this->input->post('phone'),
			);
		  if ($this->ion_auth->register($username, $password, $email, $additional_data))
		  {
		  	//check to see if we are creating the user
		  	//redirect them back to the admin page
		  	$this->session->set_flashdata('success', $this->ion_auth->messages());
		  	redirect("auth", 'refresh');
		  }
		}

		//set the flash data error message if there is one
		$error = (validation_errors() ? validation_errors() : ($this->ion_auth->errors() ? $this->ion_auth->errors() : $this->session->flashdata('message')));
    if ( ! empty($error)) {
		  $this->session->set_flashdata('error', $error);
		  redirect(current_url());
    }

		//display the create user form
    $this->data['first_name'] = array(
    	'name'  => 'first_name',
    	'id'    => 'first_name',
    	'type'  => 'text',
    	'value' => $this->form_validation->set_value('first_name'),
      'autocomplete' => 'off',
    );
    $this->data['last_name'] = array(
    	'name'  => 'last_name',
    	'id'    => 'last_name',
    	'type'  => 'text',
    	'value' => $this->form_validation->set_value('last_name'),
      'autocomplete' => 'off',
    );
    $this->data['username'] = array(
    	'name'  => 'username',
    	'id'    => 'username',
    	'type'  => 'text',
    	'value' => $this->form_validation->set_value('username'),
      'autocomplete' => 'off',
    );
    $this->data['email'] = array(
    	'name'  => 'email',
    	'id'    => 'email',
    	'type'  => 'text',
    	'value' => $this->form_validation->set_value('email'),
      'autocomplete' => 'off',
    );
    $this->data['company'] = array(
    	'name'  => 'company',
    	'id'    => 'company',
    	'type'  => 'text',
    	'value' => $this->form_validation->set_value('company'),
      'autocomplete' => 'off',
    );
    $this->data['phone'] = array(
    	'name'  => 'phone',
    	'id'    => 'phone',
    	'type'  => 'text',
    	'value' => $this->form_validation->set_value('phone'),
      'autocomplete' => 'off',
    );
    $this->data['password'] = array(
    	'name'  => 'password',
    	'id'    => 'password',
    	'type'  => 'password',
    	'value' => $this->form_validation->set_value('password'),
      'autocomplete' => 'off',
    );
    $this->data['password_confirm'] = array(
    	'name'  => 'password_confirm',
    	'id'    => 'password_confirm',
    	'type'  => 'password',
    	'value' => $this->form_validation->set_value('password_confirm'),
      'autocomplete' => 'off',
    );
    
    $this->data['title'] = 'Create user';
    $this->data['content'] = 'auth/create_user';
    
    $this->load->view($this->editor_layout, $this->data);
	}

	/**
   * Delete User
   */
	function delete_user($id = NULL)
	{
		$id = $this->config->item('use_mongodb', 'ion_auth') ? (string) $id : (int) $id;

		$this->load->library('form_validation');
		$this->form_validation->set_rules('confirm', $this->lang->line('deactivate_validation_confirm_label'), 'required');
		$this->form_validation->set_rules('id', $this->lang->line('deactivate_validation_user_id_label'), 'required|alpha_numeric');

		if ($this->form_validation->run() == FALSE)
		{
			// insert csrf check
			$this->data['csrf'] = $this->_get_csrf_nonce();
			$this->data['user'] = $this->ion_auth->user($id)->row();

      $this->data['title'] = 'Delete user';
      $this->data['content'] = 'auth/delete_user';

      $this->load->view($this->editor_layout, $this->data);
		}
		else
		{
			// do we really want to delete?
			if ($this->input->post('confirm') == 'yes')
			{
				// do we have a valid request?
				if ($this->_valid_csrf_nonce() === FALSE || $id != $this->input->post('id'))
				{
					show_error($this->lang->line('error_csrf'));
				}

				// do we have the right userlevel?
				if ($this->ion_auth->logged_in() && $this->ion_auth->is_admin())
				{
					$this->ion_auth->delete_user($id);
				  $this->session->set_flashdata('success', "User removed.");
				}
			}

			//redirect them back to the auth page
			redirect('auth', 'refresh');
		}
	}

	//edit a user
	function edit_user($id)
	{
		$this->data['title'] = "Edit User";

		if (!$this->ion_auth->logged_in() || !$this->ion_auth->is_admin())
		{
			redirect('auth', 'refresh');
		}

		$user = $this->ion_auth->user($id)->row();
		$groups=$this->ion_auth->groups()->result_array();
		$currentGroups = $this->ion_auth->get_users_groups($id)->result();

		//validate form input
		$this->form_validation->set_rules('first_name', $this->lang->line('edit_user_validation_fname_label'), 'trim|xss_clean');
		$this->form_validation->set_rules('last_name', $this->lang->line('edit_user_validation_lname_label'), 'trim|xss_clean');
		$this->form_validation->set_rules('groups', $this->lang->line('edit_user_validation_groups_label'), 'xss_clean');

		if (isset($_POST) && !empty($_POST))
		{
			// do we have a valid request?
			if ($this->_valid_csrf_nonce() === FALSE || $id != $this->input->post('id'))
			{
				show_error($this->lang->line('error_csrf'));
			}

			$data = array(
				'first_name' => $this->input->post('first_name'),
				'last_name'  => $this->input->post('last_name'),
				'username'   => $this->input->post('username'),
				'email'      => $this->input->post('email'),
				'company'    => $this->input->post('company'),
				'company'    => $this->input->post('company'),
				'phone'      => $this->input->post('phone'),
			);

			//Update the groups user belongs to
			$groupData = $this->input->post('groups');

			if (isset($groupData) && !empty($groupData)) {

				$this->ion_auth->remove_from_group('', $id);

				foreach ($groupData as $grp) {
					$this->ion_auth->add_to_group($grp, $id);
				}

			}

			//update the password if it was posted
			if ($this->input->post('password'))
			{
				$this->form_validation->set_rules('password', $this->lang->line('edit_user_validation_password_label'), 'required|min_length[' . $this->config->item('min_password_length', 'ion_auth') . ']|max_length[' . $this->config->item('max_password_length', 'ion_auth') . ']|matches[password_confirm]');
				$this->form_validation->set_rules('password_confirm', $this->lang->line('edit_user_validation_password_confirm_label'), 'required');

				$data['password'] = $this->input->post('password');
			}

			if ($this->form_validation->run() === TRUE)
			{
				$this->ion_auth->update($user->id, $data);

				//check to see if we are creating the user
				//redirect them back to the admin page
				$this->session->set_flashdata('success', "User saved.");
				redirect("auth", 'refresh');
			}
		}

		//display the edit user form
		$this->data['csrf'] = $this->_get_csrf_nonce();

		//set the flash data error message if there is one

		//pass the user to the view
		$this->data['user'] = $user;
		$this->data['groups'] = $groups;
		$this->data['currentGroups'] = $currentGroups;

		$this->data['first_name'] = array(
			'name'  => 'first_name',
			'id'    => 'first_name',
			'type'  => 'text',
			'value' => $this->form_validation->set_value('first_name', $user->first_name),
			'autocomplete' => 'off',
		);
		$this->data['last_name'] = array(
			'name'  => 'last_name',
			'id'    => 'last_name',
			'type'  => 'text',
			'value' => $this->form_validation->set_value('last_name', $user->last_name),
			'autocomplete' => 'off',
		);
  	$this->data['username'] = array(
  		'name'  => 'username',
  		'id'    => 'username',
  		'type'  => 'text',
  		'value' => $this->form_validation->set_value('username', $user->username),
  	);
  	$this->data['email'] = array(
  		'name'  => 'email',
  		'id'    => 'email',
  		'type'  => 'text',
  		'value' => $this->form_validation->set_value('email', $user->email),
  	);
		$this->data['company'] = array(
			'name'  => 'company',
			'id'    => 'company',
			'type'  => 'text',
			'value' => $this->form_validation->set_value('company', $user->company),
			'autocomplete' => 'off',
		);
		$this->data['phone'] = array(
			'name'  => 'phone',
			'id'    => 'phone',
			'type'  => 'text',
			'value' => $this->form_validation->set_value('phone', $user->phone),
			'autocomplete' => 'off',
		);
		$this->data['password'] = array(
			'name' => 'password',
			'id'   => 'password',
			'type' => 'password',
			'value' => $this->form_validation->set_value('password', ''),
			'autocomplete' => 'off',
		);
		$this->data['password_confirm'] = array(
			'name' => 'password_confirm',
			'id'   => 'password_confirm',
			'type' => 'password',
			'value' => $this->form_validation->set_value('password', ''),
			'autocomplete' => 'off',
		);

    $this->data['title'] = 'Edit user';
    $this->data['content'] = 'auth/edit_user';

    $this->load->view($this->editor_layout, $this->data);
    
	}

  /** 
   * Groups
   */
  function groups() {
    redirect_all_nonadmin();

    $data['title'] = 'Groups';
    $data['content'] = 'auth/groups';
		$data['groups'] = $this->ion_auth->groups()->result();
  
    $this->load->view($this->editor_layout, $data);
  }


	// create a new group
	function create_group()
	{
		$this->data['title'] = $this->lang->line('create_group_title');

		if (!$this->ion_auth->logged_in() || !$this->ion_auth->is_admin())
		{
			redirect('auth', 'refresh');
		}

		//validate form input
		$this->form_validation->set_rules('group_name', $this->lang->line('create_group_validation_name_label'), 'required|alpha_dash|xss_clean');
		$this->form_validation->set_rules('description', $this->lang->line('create_group_validation_desc_label'), 'xss_clean');

		if ($this->form_validation->run() == TRUE)
		{
			$new_group_id = $this->ion_auth->create_group($this->input->post('group_name'), $this->input->post('description'));
			if($new_group_id)
			{
				// check to see if we are creating the group
				// redirect them back to the admin page
				$this->session->set_flashdata('success', $this->ion_auth->messages());
				redirect("auth", 'refresh');
			}
		}
		else
		{
			//display the create group form
			//set the flash data error message if there is one
		  $error = (validation_errors() ? validation_errors() : ($this->ion_auth->errors() ? $this->ion_auth->errors() : $this->session->flashdata('message')));
      if ( ! empty($error)) {
		    $this->session->set_flashdata('error', $error);
		    redirect(current_url());
      }

			$this->data['group_name'] = array(
				'name'  => 'group_name',
				'id'    => 'group_name',
				'type'  => 'text',
				'value' => $this->form_validation->set_value('group_name'),
			);
			$this->data['description'] = array(
				'name'  => 'description',
				'id'    => 'description',
				'type'  => 'text',
				'value' => $this->form_validation->set_value('description'),
			);

      $this->data['title'] = 'Create group';
      $this->data['content'] = 'auth/create_group';

      $this->load->view($this->editor_layout, $this->data);
      
		}
	}

	/**
   * Delete Group
   */
	function delete_group($id = NULL)
	{
		$id = $this->config->item('use_mongodb', 'ion_auth') ? (string) $id : (int) $id;

		$this->load->library('form_validation');
		$this->form_validation->set_rules('confirm', $this->lang->line('deactivate_validation_confirm_label'), 'required');
		$this->form_validation->set_rules('id', $this->lang->line('deactivate_validation_user_id_label'), 'required|alpha_numeric');

		if ($this->form_validation->run() == FALSE)
		{
			// insert csrf check
			$this->data['csrf'] = $this->_get_csrf_nonce();
			$this->data['group'] = $this->ion_auth->group($id)->row();

      $this->data['title'] = 'Delete group';
      $this->data['content'] = 'auth/delete_group';

      $this->load->view($this->editor_layout, $this->data);
		}
		else
		{
			// do we really want to delete?
			if ($this->input->post('confirm') == 'yes')
			{
				// do we have a valid request?
				if ($this->_valid_csrf_nonce() === FALSE || $id != $this->input->post('id'))
				{
					show_error($this->lang->line('error_csrf'));
				}

				// do we have the right userlevel?
				if ($this->ion_auth->logged_in() && $this->ion_auth->is_admin())
				{
					$this->ion_auth->delete_group($id);
				  $this->session->set_flashdata('success', "Group removed.");
				}
			}

			//redirect them back to the auth page
			redirect('auth', 'refresh');
		}
	}

	//edit a group
	function edit_group($id)
	{
		// bail if no group id given
		if(!$id || empty($id))
		{
			redirect('auth', 'refresh');
		}

		$this->data['title'] = $this->lang->line('edit_group_title');

		if (!$this->ion_auth->logged_in() || !$this->ion_auth->is_admin())
		{
			redirect('auth', 'refresh');
		}

		$group = $this->ion_auth->group($id)->row();

		//validate form input
		$this->form_validation->set_rules('group_name', $this->lang->line('edit_group_validation_name_label'), 'required|alpha_dash|xss_clean');
		$this->form_validation->set_rules('group_description', $this->lang->line('edit_group_validation_desc_label'), 'xss_clean');

		if (isset($_POST) && !empty($_POST))
		{
			if ($this->form_validation->run() === TRUE)
			{
				$group_update = $this->ion_auth->update_group($id, $_POST['group_name'], $_POST['group_description']);

				if($group_update)
				{
					$this->session->set_flashdata('success', $this->lang->line('edit_group_saved'));
				}
				else
				{
					$this->session->set_flashdata('error', $this->ion_auth->errors());
				}
				redirect("auth", 'refresh');
			}
		}

		//set the flash data error message if there is one
		$error = (validation_errors() ? validation_errors() : ($this->ion_auth->errors() ? $this->ion_auth->errors() : $this->session->flashdata('message')));
    if ( ! empty($error)) {
		  $this->session->set_flashdata('error', $error);
		  redirect(current_url());
    }

		//pass the user to the view
		$this->data['group'] = $group;

		$this->data['group_name'] = array(
			'name'  => 'group_name',
			'id'    => 'group_name',
			'type'  => 'text',
			'value' => $this->form_validation->set_value('group_name', $group->name),
		);
		$this->data['group_description'] = array(
			'name'  => 'group_description',
			'id'    => 'group_description',
			'type'  => 'text',
			'value' => $this->form_validation->set_value('group_description', $group->description),
		);

    $this->data['title'] = 'Edit group';
    $this->data['content'] = 'auth/edit_group';

    $this->load->view($this->editor_layout, $this->data);
    
	}


	function _get_csrf_nonce()
	{
		$this->load->helper('string');
		$key   = random_string('alnum', 8);
		$value = random_string('alnum', 20);
		$this->session->set_flashdata('csrfkey', $key);
		$this->session->set_flashdata('csrfvalue', $value);

		return array($key => $value);
	}

	function _valid_csrf_nonce()
	{
		if ($this->input->post($this->session->flashdata('csrfkey')) !== FALSE &&
			$this->input->post($this->session->flashdata('csrfkey')) == $this->session->flashdata('csrfvalue'))
		{
			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}

	function _render_page($view, $data=null, $render=false)
	{

		$this->viewdata = (empty($data)) ? $this->data: $data;

		$view_html = $this->load->view($view, $this->viewdata, $render);

		if (!$render) return $view_html;
	}
	
}

