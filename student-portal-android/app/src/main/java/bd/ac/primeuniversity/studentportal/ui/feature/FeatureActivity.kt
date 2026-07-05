package bd.ac.primeuniversity.studentportal.ui.feature

import android.content.Context
import android.content.Intent
import android.os.Bundle
import androidx.annotation.ColorRes
import androidx.annotation.DrawableRes
import androidx.annotation.StringRes
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.core.graphics.ColorUtils
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.databinding.ActivityFeatureBinding
import bd.ac.primeuniversity.studentportal.ui.dashboard.Feature

/**
 * Lightweight detail screen shown for features whose data source is not yet
 * wired into the mobile API. Presents a titled, branded "coming soon" state so
 * the full menu is navigable end-to-end.
 */
class FeatureActivity : AppCompatActivity() {

    private lateinit var binding: ActivityFeatureBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityFeatureBinding.inflate(layoutInflater)
        setContentView(binding.root)

        val titleRes = intent.getIntExtra(EXTRA_TITLE, R.string.app_name)
        val iconRes = intent.getIntExtra(EXTRA_ICON, R.drawable.ic_info)
        val colorRes = intent.getIntExtra(EXTRA_COLOR, R.color.primary)

        binding.toolbar.setTitle(titleRes)
        binding.toolbar.setNavigationOnClickListener { finish() }

        binding.featureTitle.setText(titleRes)
        binding.featureIcon.setImageResource(iconRes)

        val accent = ContextCompat.getColor(this, colorRes)
        binding.featureIcon.setColorFilter(accent)
        binding.iconContainer.background?.mutate()
            ?.setTint(ColorUtils.setAlphaComponent(accent, 30))
    }

    companion object {
        private const val EXTRA_TITLE = "title"
        private const val EXTRA_ICON = "icon"
        private const val EXTRA_COLOR = "color"

        fun intent(context: Context, feature: Feature): Intent =
            open(context, feature.titleRes, feature.iconRes, feature.colorRes)

        fun open(
            context: Context,
            @StringRes titleRes: Int,
            @DrawableRes iconRes: Int,
            @ColorRes colorRes: Int,
        ): Intent = Intent(context, FeatureActivity::class.java)
            .putExtra(EXTRA_TITLE, titleRes)
            .putExtra(EXTRA_ICON, iconRes)
            .putExtra(EXTRA_COLOR, colorRes)
    }
}
