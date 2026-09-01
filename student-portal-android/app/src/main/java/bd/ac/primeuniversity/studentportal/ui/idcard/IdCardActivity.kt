package bd.ac.primeuniversity.studentportal.ui.idcard

import android.graphics.Color
import android.os.Bundle
import android.view.View
import android.webkit.WebView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.DigitalIdCard
import bd.ac.primeuniversity.studentportal.databinding.ActivityIdCardBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch

/**
 * Digital student ID card.
 *
 * Shows the student's OFFICIAL ID card (front + back), rendered server-side
 * from the same design the admin ID Card module prints, together with the
 * card's print/collection status. The status stays visible until the card
 * is collected by the student. When the university has not generated an ID
 * card yet, a "No ID card found" empty state is shown.
 */
class IdCardActivity : AppCompatActivity() {

    private lateinit var binding: ActivityIdCardBinding
    private val app: PrimeApp by lazy { application as PrimeApp }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityIdCardBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.toolbar.setNavigationOnClickListener { finish() }
        load()
    }

    private fun load() {
        binding.progress.visibility = View.VISIBLE
        binding.officialContainer.visibility = View.GONE
        binding.emptyState.visibility = View.GONE

        lifecycleScope.launch {
            when (val result = app.repository.getIdCard()) {
                is AppResult.Success -> {
                    val card = result.data.takeIf { it.hasCard }?.card
                    if (card != null && !card.frontSvg.isNullOrBlank()) {
                        showCard(card)
                    } else {
                        showEmpty()
                    }
                }
                is AppResult.Error -> {
                    Toast.makeText(this@IdCardActivity, result.message, Toast.LENGTH_LONG).show()
                    showEmpty()
                }
            }
            binding.progress.visibility = View.GONE
        }
    }

    private fun showCard(card: DigitalIdCard) {
        binding.officialContainer.visibility = View.VISIBLE

        // Print/collection status – visible until the student collects the card.
        val status = card.printStatus?.takeIf { it.isNotBlank() }
        if (status != null && status != STATUS_COLLECTED) {
            binding.cardStatus.visibility = View.VISIBLE
            binding.cardStatus.text = getString(
                R.string.id_card_status,
                card.printStatusLabel?.takeIf { it.isNotBlank() } ?: status,
            )
        } else {
            binding.cardStatus.visibility = View.GONE
        }

        loadCardSide(binding.frontWebView, card.frontSvg!!)

        val back = card.backSvg
        if (!back.isNullOrBlank()) {
            loadCardSide(binding.backWebView, back)
        } else {
            binding.backCard.visibility = View.GONE
        }

        val expiry = card.expiryDate?.takeIf { it.isNotBlank() }
        binding.cardValidity.text =
            if (expiry != null) getString(R.string.id_card_valid_until, expiry)
            else getString(R.string.id_card_valid)
    }

    private fun showEmpty() {
        binding.emptyState.visibility = View.VISIBLE
    }

    /** Renders one card side (SVG) in a WebView, keeping the printed aspect ratio. */
    private fun loadCardSide(webView: WebView, svg: String) {
        webView.setBackgroundColor(Color.TRANSPARENT)
        webView.isVerticalScrollBarEnabled = false
        webView.isHorizontalScrollBarEnabled = false
        webView.settings.loadWithOverviewMode = true
        webView.settings.useWideViewPort = true

        // Match the printed card's 331.2 x 212.16 aspect ratio.
        webView.post {
            val width = webView.width
            if (width > 0) {
                val params = webView.layoutParams
                params.height = (width * CARD_ASPECT).toInt()
                webView.layoutParams = params
            }
        }

        val html = """
            <!DOCTYPE html><html><head>
            <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
            <style>
              html,body{margin:0;padding:0;background:transparent;overflow:hidden}
              svg{display:block;width:100vw;height:auto}
            </style>
            </head><body>$svg</body></html>
        """.trimIndent()
        webView.loadDataWithBaseURL(null, html, "text/html", "utf-8", null)
    }

    companion object {
        /** idc_cards.print_status value after which the status banner is hidden. */
        private const val STATUS_COLLECTED = "collected"

        /** Height / width of the printed card design (212.16 / 331.2). */
        private const val CARD_ASPECT = 212.16f / 331.2f
    }
}
