from flask import Flask, render_template, request, redirect, session, send_from_directory
import os
import pyodbc

# Flask configuration
# template_folder="."  → HTML files are in the same folder
# static_folder="."    → CSS / images are also in the same folder
app = Flask(
    __name__,
    template_folder=".",
    static_folder="."
)

app.secret_key = "servitech-secret"

# SQL Server connection
conn = pyodbc.connect(
    "DRIVER={ODBC Driver 17 for SQL Server};"
    "SERVER=<your-server-ip>\\SQLEXPRESS;"
    "DATABASE=ServiTechDB;"
    "UID=<user>;"
    "PWD=<password>"
)

cursor = conn.cursor()

# LOGIN PAGE
@app.route("/")
def login_page():
    return render_template("loggingIn.html")


# Serve files from the sibling `main/` directory at `/main/<path:filename>`
@app.route('/main/<path:filename>')
def serve_main_file(filename):
    main_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'main'))
    return send_from_directory(main_dir, filename)

# HANDLE LOGIN
@app.route("/login", methods=["POST"])
def login():
    email = request.form["email"]
    password = request.form["password"]

    cursor.execute(
        "SELECT * FROM admins WHERE email=? AND password=?",
        (email, password)
    )

    admin = cursor.fetchone()

    if admin:
        session["admin_logged_in"] = True
        return redirect("/admin")
    else:
        return redirect("/")

# ADMIN PAGE (PROTECTED)
@app.route("/admin")
def admin():
    if not session.get("admin_logged_in"):
        return redirect("/")
    return render_template("admin.html")

# LOGOUT
@app.route("/logout")
def logout():
    session.clear()
    return redirect("/")

# RUN SERVER
if __name__ == "__main__":
    app.run(debug=True)
