# Guild Voting System

Demo web-based voting system for an institution.

## Overview

This Guild Voting System is designed for student institutions to facilitate secure, user-friendly voting processes. Its key features include:

- **Student Role:**
  - Cast votes securely.
  - View vote tallies in real-time.
  - Enjoy both light and dark themes for a personalized experience.

- **Administrator Role:**
  - Add new students to the system.
  - Block or allow students from voting as needed.
  - Promote students to administrators to assist in managing the voting proccess.
  - Access both light and dark themes for the admin interface.

## Setup Instructions

Follow these steps to install and test the system locally:

1. **Clone the Repository**
   
   ```bash
   git clone https://github.com/Josh03-spec/Guild_voting_system.git
   ```

2. **Set Up a Local Web Server**

   Use XAMPP, WAMP, LAMP (FOR LINUX), or any PHP-supported local web server.

3. **Install the Database**

   - Open [phpMyAdmin](https://localhost/phpmyadmin) in your browser.
   - Create a new database named `voting_system`.
   - Import the provided database dump file (named `voting_system.sql`):
     1. Select the `voting_system` database.
     2. Click on “Import”.
     3. Choose the SQL dump file from the repository.
     4. Execute the import.

4. **Configure Server Settings (if required)**

   - Ensure your web server is set to use PHP 7.x or later.
   - Place all repository files inside your web server’s root directory (e.g., `htdocs` for XAMPP).

5. **Run the Application**

   - Access the system in your browser:
     ```
     http://localhost/voting_system/
     ```

## Default Login Credentials

Here are test credentials for both user roles:

### Administrator

- Email: `admin@umu.ac.ug`
- Password: `Admin@1234`

### Student

- Email: `namaganda.olive@stud.umu.ac.ug`
- Password: `Namaganda2004`

---

**Note:**

For production use, remember to change the default credentials and further secure your installation.

Edit the db.php file in the shared files and update with the connection details on the database
