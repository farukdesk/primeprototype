package bd.ac.primeuniversity.studentportal.ui.login

import android.content.Intent
import android.os.Bundle
import android.view.View
import androidx.activity.viewModels
import androidx.appcompat.app.AppCompatActivity
import bd.ac.primeuniversity.studentportal.data.local.SecureStorage
import bd.ac.primeuniversity.studentportal.databinding.ActivityLoginBinding
import bd.ac.primeuniversity.studentportal.ui.main.MainActivity
import bd.ac.primeuniversity.studentportal.ui.staff.StaffMainActivity
import java.util.Calendar

/** Sign-in screen for students and staff/employees (same form). */
class LoginActivity : AppCompatActivity() {

    private lateinit var binding: ActivityLoginBinding
    private val viewModel: LoginViewModel by viewModels()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityLoginBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.footer.text = getString(
            bd.ac.primeuniversity.studentportal.R.string.copyright,
            Calendar.getInstance().get(Calendar.YEAR).toString()
        )

        binding.btnSignIn.setOnClickListener { attemptLogin() }
        binding.inputPassword.editText?.setOnEditorActionListener { _, _, _ ->
            attemptLogin(); true
        }

        observe()
    }

    private fun attemptLogin() {
        val login = binding.inputLogin.editText?.text?.toString()?.trim().orEmpty()
        val password = binding.inputPassword.editText?.text?.toString().orEmpty()

        var valid = true
        if (login.isEmpty()) {
            binding.inputLogin.error = getString(bd.ac.primeuniversity.studentportal.R.string.required)
            valid = false
        } else binding.inputLogin.error = null

        if (password.isEmpty()) {
            binding.inputPassword.error = getString(bd.ac.primeuniversity.studentportal.R.string.required)
            valid = false
        } else binding.inputPassword.error = null

        if (valid) viewModel.login(login, password)
    }

    private fun observe() {
        viewModel.loading.observe(this) { loading ->
            binding.progress.visibility = if (loading) View.VISIBLE else View.GONE
            binding.btnSignIn.isEnabled = !loading
            binding.btnSignIn.text = if (loading) "" else
                getString(bd.ac.primeuniversity.studentportal.R.string.action_sign_in)
        }
        viewModel.error.observe(this) { message ->
            if (message.isNullOrEmpty()) {
                binding.errorBox.visibility = View.GONE
            } else {
                binding.errorBox.visibility = View.VISIBLE
                binding.errorText.text = message
            }
        }
        viewModel.success.observe(this) { role ->
            if (role != null) {
                val target = if (role == SecureStorage.ROLE_STAFF) {
                    StaffMainActivity::class.java
                } else {
                    MainActivity::class.java
                }
                startActivity(Intent(this, target))
                finishAffinity()
            }
        }
    }
}
