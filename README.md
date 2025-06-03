# 🥦 Freshcery - AI-Driven Grocery E-Commerce Platform

**Freshcery** is an advanced e-commerce system designed for seamless online grocery shopping with AI-powered customer support.

## 🚀 Features

### 🧠 AI-Powered Customer Assistant
- Gemini Pro + Ollama fallback for 24x7 AI support
- Redis cache for instant repeated replies
- Fully logged Q/A system (MySQL `ai_logs` table)

### 🛒 Complete E-Commerce Stack
- Product, Cart, and Order management
- User authentication and profile system
- Category management with images/icons
- Admin panel with full control over platform

### 🛡️ Tech Stack & Security
- PHP 7.4, MySQL (InnoDB), XAMPP
- Redis for caching and rate limiting (planned)
- Dockerized deployment (Ollama + Redis)
- Password hashing, IP logging, input sanitization

### 📦 Deployment-Ready
- Easily deploy on local/cloud using Docker
- Secure .env API key handling


📁 Project Structure

Freshcery-online-grocery-store/

    
    ├── admin-panel/       # Admin dashboard and management tools
    ├── assets/            # Images, stylesheets, and scripts
    ├── auth/              # User authentication scripts
    ├── config/            # Database configuration files
    ├── includes/          # Reusable PHP components (e.g., header, footer)
    ├── products/          # Product-related scripts and data
    ├── users/             # User profile and account management
    ├── index.php          # Homepage
    ├── shop.php           # Main shopping page
    ├── about.php          # About us page
    ├── contact.php        # Contact information and form
    ├── faq.php            # Frequently asked questions
    ├── 404.php            # Custom 404 error page
    ├── freshcery.sql      # SQL file to set up the database
    └── README.md          # Project documentation

## 📸 Screenshots

### 🏠 Home Page  
![Home](image/homepage.png)

### 🔐 Login Page  
![Login](image/login.png)

### 📝 Register Page  
![Register](image/register.png)

### ❓ FAQ Section  
![FAQ](image/faq.png)

### 📞 Contact Page  
![Contact](image/contact.png)

### 🛍️ Shop / Products Page  
![Shop](image/shop.png)

### 🛒 Cart Page  
![Cart](image/cart.png)

### 👤 Transactions 
![Transactions](image/transactions.png)

### ⚙️ Settings
![Settings](image/settings.png)

⚙️ Getting Started

Prerequisites


    PHP 7.2 or higher
    
    MySQL or compatible database
    
    Web server (e.g., Apache, Nginx)

Installation:

Clone the Repository;

    bash
    Copy
    Edit
    git clone https://github.com/bhaktofmahakal/Freshcery-online-grocery-store.git
    
Set Up the Database:

Create a new MySQL database named freshcery.

Setup the freshcery.sql file located in the project root to set up the necessary tables and data.

Configure Database Connection:

Navigate to the config/ directory.

Open the database configuration file (e.g., config.php) and update the database credentials:

    define('DB_SERVER', 'localhost');
    define('DB_USERNAME', 'your_username');
    define('DB_PASSWORD', 'your_password');
    define('DB_NAME', 'freshcery');
    
Deploy the Application:

Place the project folder in your web server's root directory (e.g., htdocs for XAMPP).

Start your web server and navigate to

    http://localhost/Freshcery-online-grocery-store/ in your browser.

🤝 Contributing

    Contributions are welcome! To contribute:

Fork the repository.

Create a new branch:

    git checkout -b feature/YourFeature
    
Commit your changes:

  
    git commit -m "Add YourFeature"
    
Push to the branch:

   
    git push origin feature/YourFeature
    
Open a pull request describing your changes.

📄 License

    This project is licensed under the MIT License.

📬 Contact

For any inquiries or feedback, please contact 

    utsavmishraa005@gmail.com


