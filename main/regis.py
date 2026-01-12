import random
import pyodbc
from flask import Flask, request, jsonify, render_template, session, redirect, url_for, send_from_directory
import os
from flask_mail import Mail, Message
from werkzeug.security import generate_password_hash

app = Flask(__name__)
app.secret_key = "secret123"


# Serve files from the `main/` directory at the URL path `/main/<path:filename>`
@app.route('/main/<path:filename>')
def serve_main_file(filename):
    root = app.root_path  # this file lives in the `main/` folder
    return send_from_directory(root, filename)

# ================= EMAIL CONFIG =================
app.config["MAIL_SERVER"] = "smtp.gmail.com"
app.config["MAIL_PORT"] = 587
app.config["MAIL_USE_TLS"] = True
app.config["MAIL_USERNAME"] = "yourgmail@gmail.com"   # CHANGE THIS
app.config["MAIL_PASSWORD"] = "uajptunqmbbjiots"    # APP PASSWORD

mail = Mail(app)

# ================= DATABASE CONNECTION =================
def get_db_connection():
    return pyodbc.connect(
        "DRIVER={ODBC Driver 17 for SQL Server};"
        "SERVER=localhost;"
        "DATABASE=ServiTechDB;"
        "Trusted_Connection=yes;"
    )

# Temporary storage for verification
temp_user = {}

# ================= ROUTES =================

# Register page
@app.route("/")
def home():
    return render_template("regis.html")


# Landing page (FIXES BuildError)
@app.route("/landing")
def landing():
    return render_template("landing.html")


# Login page (FIXES BuildError)
@app.route("/login", methods=["GET", "POST"])
def log_in():
    # Simple login: admin/admin -> admin dashboard
    if request.method == "POST":
        username = request.form.get("username")
        password = request.form.get("password")
        if username == "admin" and password == "admin":
            session["is_admin"] = True
            return redirect(url_for("admin_dashboard"))
        return render_template("log_in.html", error="Invalid credentials")

    return render_template("log_in.html")


@app.route("/logout")
def logout():
    session.pop("is_admin", None)
    return redirect(url_for("log_in"))


def get_counts():
    customers = 0
    orders = 0
    queue = 0
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        try:
            cursor.execute("SELECT COUNT(*) FROM users")
            customers = cursor.fetchone()[0] or 0
        except Exception:
            customers = 0
        try:
            cursor.execute("SELECT COUNT(*) FROM orders")
            orders = cursor.fetchone()[0] or 0
        except Exception:
            orders = 0
        try:
            cursor.execute("SELECT COUNT(*) FROM queue")
            queue = cursor.fetchone()[0] or 0
        except Exception:
            queue = 0
        cursor.close()
        conn.close()
    except Exception as e:
        print("Count fetch error:", e)

    return customers, orders, queue


@app.route('/admin')
def admin_dashboard():
    if not session.get('is_admin'):
        return redirect(url_for('log_in'))
    customers, orders, queue = get_counts()
    return render_template('admin.html', customers=customers, orders=orders, queue=queue)


# ================= REGISTER =================
@app.route("/register", methods=["POST"])
def register():
    global temp_user
    data = request.get_json()

    # Validate input
    required_fields = ["fullname", "contact", "email", "password"]
    if not all(field in data for field in required_fields):
        return jsonify({"success": False, "message": "Missing fields"}), 400

    # Generate verification code
    code = str(random.randint(100000, 999999))

    # Hash password
    hashed_password = generate_password_hash(data["password"])

    # Store temporarily
    temp_user = {
        "fullname": data["fullname"],
        "contact": data["contact"],
        "email": data["email"],
        "password": hashed_password,
        "code": code
    }

    try:
        msg = Message(
            subject="ServiTech Verification Code",
            sender=app.config["MAIL_USERNAME"],
            recipients=[data["email"]],
            body=f"Your ServiTech verification code is: {code}"
        )
        mail.send(msg)
    except Exception as e:
        print("Email Error:", e)
        return jsonify({"success": False, "message": "Email failed"}), 500

    return jsonify({"success": True})


# ================= VERIFY =================
@app.route("/verify", methods=["POST"])
def verify():
    data = request.get_json()

    if not temp_user:
        return jsonify({"success": False, "message": "No pending registration"}), 400

    if data.get("code") != temp_user["code"]:
        return jsonify({"success": False, "message": "Invalid code"}), 400

    try:
        conn = get_db_connection()
        cursor = conn.cursor()

        cursor.execute(
            """
            INSERT INTO users (fullname, contact, email, password)
            VALUES (?, ?, ?, ?)
            """,
            temp_user["fullname"],
            temp_user["contact"],
            temp_user["email"],
            temp_user["password"]
        )

        conn.commit()
        cursor.close()
        conn.close()

    except Exception as e:
        print("Database Error:", e)
        return jsonify({"success": False, "message": "Database error"}), 500

    temp_user.clear()
    return jsonify({"success": True})


# ================= RUN APP =================
if __name__ == "__main__":
    app.run(debug=True)
