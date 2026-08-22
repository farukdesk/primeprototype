package bd.ac.primeuniversity.studentportal.ui.admitcard

import android.content.ActivityNotFoundException
import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.FileProvider
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import bd.ac.primeuniversity.studentportal.BuildConfig
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.AdmitCard
import bd.ac.primeuniversity.studentportal.databinding.ActivityAdmitCardsBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch
import java.io.File

/**
 * Admit Card screen. Lists the active admit cards published for the
 * student's department + program (admin/api/student/admit-cards.php) and
 * downloads the PDF (admit-card-download.php) so it can be viewed or shared.
 */
class AdmitCardsActivity : AppCompatActivity() {

    private lateinit var binding: ActivityAdmitCardsBinding
    private val app: PrimeApp by lazy { application as PrimeApp }
    private val adapter = AdmitCardAdapter { onCard(it) }
    private var downloading = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityAdmitCardsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }
        binding.list.layoutManager = LinearLayoutManager(this)
        binding.list.adapter = adapter

        binding.swipeRefresh.setColorSchemeResources(
            R.color.primary, R.color.accent, R.color.cat_exam
        )
        binding.swipeRefresh.setOnRefreshListener { load() }

        load(initial = true)
    }

    private fun load(initial: Boolean = false) {
        if (initial) binding.progress.visibility = View.VISIBLE
        binding.emptyState.visibility = View.GONE

        lifecycleScope.launch {
            when (val result = app.repository.getAdmitCards()) {
                is AppResult.Success -> {
                    adapter.submitList(result.data.admitCards)
                    binding.emptyState.visibility =
                        if (result.data.admitCards.isEmpty()) View.VISIBLE else View.GONE
                }
                is AppResult.Error -> {
                    Toast.makeText(this@AdmitCardsActivity, result.message, Toast.LENGTH_LONG).show()
                    if (adapter.itemCount == 0) binding.emptyState.visibility = View.VISIBLE
                }
            }
            binding.progress.visibility = View.GONE
            binding.swipeRefresh.isRefreshing = false
        }
    }

    private fun onCard(card: AdmitCard) {
        if (!card.allowed) {
            AlertDialog.Builder(this)
                .setTitle(R.string.admit_card_blocked_title)
                .setMessage(card.reason?.takeIf { it.isNotBlank() }
                    ?: getString(R.string.admit_card_blocked_body))
                .setPositiveButton(R.string.close, null)
                .show()
            return
        }
        if (downloading) return
        downloading = true
        binding.progress.visibility = View.VISIBLE

        lifecycleScope.launch {
            val target = File(File(cacheDir, "admit-cards"), "admit-card-${card.id}.pdf")
            when (val result = app.repository.downloadAdmitCard(card.id, target)) {
                is AppResult.Success -> openPdf(result.data)
                is AppResult.Error ->
                    Toast.makeText(this@AdmitCardsActivity, result.message, Toast.LENGTH_LONG).show()
            }
            binding.progress.visibility = View.GONE
            downloading = false
        }
    }

    /** Opens the PDF in an external viewer; falls back to the share sheet. */
    private fun openPdf(file: File) {
        val uri = FileProvider.getUriForFile(
            this, "${BuildConfig.APPLICATION_ID}.fileprovider", file
        )
        val view = Intent(Intent.ACTION_VIEW)
            .setDataAndType(uri, "application/pdf")
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        try {
            startActivity(view)
        } catch (e: ActivityNotFoundException) {
            try {
                val share = Intent(Intent.ACTION_SEND)
                    .setType("application/pdf")
                    .putExtra(Intent.EXTRA_STREAM, uri)
                    .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                startActivity(Intent.createChooser(share, getString(R.string.admit_card_share)))
            } catch (e: Exception) {
                Toast.makeText(this, R.string.admit_card_no_viewer, Toast.LENGTH_LONG).show()
            }
        }
    }
}
